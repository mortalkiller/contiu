<?php

use App\Filament\Pages\InvoiceAiBenchmark;
use App\Models\Account;
use App\Models\InvoiceBenchmarkDocument;
use App\Support\InvoiceBenchmark\GoogleAccessTokenProvider;
use App\Support\InvoiceBenchmark\InvoiceBenchmarkDashboard;
use App\Support\InvoiceBenchmark\InvoiceBenchmarkFixtures;
use App\Support\InvoiceBenchmark\InvoiceBenchmarkService;
use App\Support\InvoiceBenchmark\InvoiceData;
use App\Support\InvoiceBenchmark\InvoicePricingService;
use App\Support\InvoiceBenchmark\InvoiceQrCodeService;
use App\Support\InvoiceBenchmark\InvoiceValidationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\Process\ExecutableFinder;

function syntheticBenchmarkOutput(string $fixture = 'standard-qr'): array
{
    $expected = InvoiceBenchmarkFixtures::expected($fixture);

    foreach (['vat' => InvoiceData::VAT_FIELDS, 'lines' => InvoiceData::LINE_FIELDS] as $key => $fields) {
        if (isset($expected[$key])) {
            $expected[$key] = array_map(fn (array $row): array => array_replace(array_fill_keys($fields, null), $row), $expected[$key]);
        }
    }

    return array_replace(array_fill_keys([...InvoiceData::FIELDS, 'vat', 'lines'], null), $expected);
}

function fakeBenchmarkVertex(string $text, bool $retry = false): void
{
    config(['invoice-benchmark.enabled' => true, 'invoice-benchmark.live' => true, 'invoice-benchmark.project' => 'contiu-test']);
    test()->mock(GoogleAccessTokenProvider::class)->shouldReceive('token')->andReturn('fake-token');
    $endpoint = 'https://aiplatform.eu.rep.googleapis.com/v1/projects/contiu-test/locations/eu/interactions';
    $sequence = Http::sequence();
    if ($retry) {
        $sequence->push(['error' => ['code' => 429]], 429, ['Retry-After' => '0']);
    }
    $sequence->push([
        'status' => 'completed',
        'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => $text]]]],
        'usage' => ['total_input_tokens' => 200, 'total_output_tokens' => 20, 'total_thought_tokens' => 2, 'total_cached_tokens' => 10, 'total_tokens' => 222],
    ]);
    Http::preventStrayRequests();
    Http::fake([$endpoint => $sequence]);
}

it('runs one extraction per mode, audits each actual request and does not charge again on duplicate submission', function () {
    Storage::fake('local');
    $user = actingAsAdmin();
    $output = syntheticBenchmarkOutput();
    fakeBenchmarkVertex(json_encode($output, JSON_THROW_ON_ERROR), retry: true);
    $benchmark = app(InvoiceBenchmarkService::class);
    $document = $benchmark->upload(InvoiceBenchmarkFixtures::file('standard-qr'), $user->id, $this->account->id);
    $document->update(['ground_truth' => ['gross_total' => '123,0', 'invoice_number' => 'FT DEMO/001'], 'verified_at' => now()]);

    $run = $benchmark->queue($document, 'ai')->refresh();
    $duplicate = $benchmark->queue($document, 'ai');
    $stats = app(InvoiceBenchmarkDashboard::class)->summarize($benchmark->documents($user->id, $this->account->id));

    expect($run->status)->toBe('succeeded');
    expect($run->result)->toBe($output);
    expect($duplicate->id)->toBe($run->id);
    Http::assertSentCount(2);
    $requests = DB::table('invoice_benchmark_requests')->where('run_id', $run->id)->orderBy('attempt')->get();
    expect($requests->pluck('http_status')->all())->toBe([429, 200]);
    expect($requests[0]->estimated_usd)->toBeNull();
    expect((string) $requests[1]->estimated_usd)->toBe('0.000088825000');
    expect($stats['overall'])->toMatchArray(['requests' => 2, 'retries' => 1, 'correct' => 1, 'cost_samples' => 1, 'avg_tokens' => 222.0]);
    expect($stats['groups']['ai / gemini-3.1-flash-lite']['projections'][1000])->toBe('0.0888');
});

it('keeps billed usage for invalid or incomplete responses', function (string $output) {
    Storage::fake('local');
    $user = actingAsAdmin();
    fakeBenchmarkVertex($output);
    $benchmark = app(InvoiceBenchmarkService::class);
    $document = $benchmark->upload(InvoiceBenchmarkFixtures::file('standard-qr'), $user->id, $this->account->id);

    $run = $benchmark->queue($document, 'ai')->refresh();

    expect($run->status)->toBe('failed');
    expect($run->ai_output)->toBe(['text' => $output]);
    expect(DB::table('invoice_benchmark_requests')->where('run_id', $run->id)->value('estimated_usd'))->not->toBeNull();
    Http::assertSentCount(1);
})->with(['{malformed', '{"invoice_number":"FT DEMO/001"}']);

it('reads a real fiscal QR locally and preserves disagreements with AI', function () {
    if (! (new ExecutableFinder)->find('zbarimg')) {
        $this->markTestSkipped('zbarimg is required for local QR decoding.');
    }
    Storage::fake('local');
    $user = actingAsAdmin();
    $output = syntheticBenchmarkOutput();
    $output['gross_total'] = '128.00';
    $output['supplier_nif'] = null;
    fakeBenchmarkVertex(json_encode($output, JSON_THROW_ON_ERROR));
    $benchmark = app(InvoiceBenchmarkService::class);
    $document = $benchmark->upload(InvoiceBenchmarkFixtures::file('standard-qr'), $user->id, $this->account->id);

    $local = $benchmark->queue($document, 'qr')->refresh();
    $combined = $benchmark->queue($document, 'qr_ai')->refresh();

    expect($local->qr_output)->toMatchArray(['status' => 'parsed', 'detected' => true]);
    expect($local->result)->toMatchArray(['supplier_nif' => '500000000', 'gross_total' => '123.00']);
    expect($local->result)->not->toHaveKey('lines');
    expect($combined->result['supplier_nif'])->toBe('500000000');
    expect($combined->result['gross_total'])->toBe('123.00');
    expect($combined->review['differences']['gross_total'])->toBe(['qr' => '123.00', 'ai' => '128.00']);
    expect($combined->review['warnings'])->toContain('Net amount plus total tax differs from the gross total.');
    Http::assertSentCount(1);
});

it('flags invalid dates, NIF checksums and decimal or VAT inconsistencies', function () {
    $data = array_replace(syntheticBenchmarkOutput('multirate'), ['supplier_nif' => '500000001', 'issue_date' => '2026-02-29', 'vat_amount' => '99.00', 'paid_amount' => 'EUR 10']);
    $review = app(InvoiceValidationService::class)->review($data, [], []);

    expect($review['warnings'])->toContain('Invalid Portuguese supplier NIF checksum.', 'Invalid date in issue_date.', 'VAT groups do not add up to the reported VAT amount.', 'Invalid decimal in document 1 (paid_amount).');
    expect((string) InvoiceData::decimal('23,00'))->toBe('23.00');
    expect(InvoiceData::compare('FT DEMO/OO1', 'FT DEMO/001', 'invoice_number'))->toBeFalse();
});

it('reads fiscal QR tax groups by region and rejects invalid fields', function () {
    $reader = app(InvoiceQrCodeService::class);
    $header = 'A:500000000*B:999999990*C:PT*D:FT*E:N*F:20261113*G:FT 1/0001*H:TEST-0001';
    $totals = '*N:14.90*O:124.90*Q:DEMO*R:9999';

    $parsed = $reader->parse($header.'*I1:PT*I2:10.00*I3:25.00*I4:1.50*J1:PT-AC*J7:50.00*J8:8.00*K1:PT-MA*K7:25.00*K8:5.40'.$totals);

    expect(array_column($parsed['vat'], 'region'))->toBe(['PT', 'PT', 'PT-AC', 'PT-MA']);
    expect($parsed['invoice_number'])->toBe('FT 1/0001');
    expect($reader->parse($header.'*I1:PT*I8:1,25'.$totals))->toBeNull();
    expect($reader->parse('https://example.org/qr'))->toBeNull();
    expect($reader->parse(str_replace('20261113', '20260230', $header).$totals))->toBeNull();
});

it('calculates regional pricing from raw counts and leaves insufficient usage unknown', function () {
    $pricing = app(InvoicePricingService::class);
    $usage = ['total_input_tokens' => 200, 'total_output_tokens' => 20, 'total_thought_tokens' => 2, 'total_cached_tokens' => 10, 'total_tokens' => 222];

    expect($pricing->estimate($usage, config('invoice-benchmark.pricing')))->toBe('0.000088825000');
    expect($pricing->estimate([...$usage, 'total_tokens' => 221], config('invoice-benchmark.pricing')))->toBeNull();
});

it('runs demo and verifies ground truth from the page while preserving original extraction', function () {
    Storage::fake('local');
    config(['invoice-benchmark.enabled' => true, 'invoice-benchmark.live' => false]);
    actingAsAdmin();
    $this->withoutVite();
    Http::preventStrayRequests();

    Livewire::test(InvoiceAiBenchmark::class)
        ->set('fixture', 'poor-photo')
        ->call('loadDemo')
        ->assertHasNoErrors()
        ->assertSee('FT DEMO/OO1')
        ->set('correction.invoice_number', 'FT DEMO/001')
        ->call('saveGroundTruth')
        ->assertHasNoErrors()
        ->assertSee('Verified runs 1');

    $document = InvoiceBenchmarkDocument::query()->firstOrFail();
    expect($document->ground_truth['invoice_number'])->toBe('FT DEMO/001');
    expect($document->runs()->firstOrFail()->result['invoice_number'])->toBe('FT DEMO/OO1');
    expect(DB::table('invoice_benchmark_requests')->count())->toBe(0);
});

it('blocks paid modes when disabled and rejects spoofed uploads', function () {
    Storage::fake('local');
    config(['invoice-benchmark.enabled' => true, 'invoice-benchmark.live' => false]);
    $user = actingAsAdmin();
    $benchmark = app(InvoiceBenchmarkService::class);
    $document = $benchmark->upload(InvoiceBenchmarkFixtures::file('standard-qr'), $user->id, $this->account->id);

    expect(fn () => $benchmark->queue($document, 'ai'))->toThrow(RuntimeException::class, 'Live invoice AI processing is disabled.');
    expect(fn () => $benchmark->upload(UploadedFile::fake()->createWithContent('invoice.png', '<svg>invalid</svg>'), $user->id, $this->account->id))->toThrow(RuntimeException::class);
    expect($document->runs()->count())->toBe(0);
});

it('requires the feature flag and super admin access', function () {
    config(['invoice-benchmark.enabled' => false]);
    $this->withoutVite();
    $admin = actingAsAdmin();
    $route = InvoiceAiBenchmark::getUrl(tenant: $this->account);

    $this->actingAs($admin)->get($route)->assertForbidden();
    config(['invoice-benchmark.enabled' => true]);
    $this->actingAs(ownerOf($this->account))->get($route)->assertForbidden();
    $this->actingAs($admin)->get($route)->assertOk()->assertSee('Invoice AI Benchmark');
});

it('serves private previews to the owner in the correct tenant and removes expired records and files', function () {
    Storage::fake('local');
    config(['invoice-benchmark.enabled' => true]);
    $user = actingAsAdmin();
    $benchmark = app(InvoiceBenchmarkService::class);
    $document = $benchmark->upload(InvoiceBenchmarkFixtures::file('standard-qr'), $user->id, $this->account->id);
    $route = fn (Account $account): string => route('filament.admin.invoice-benchmark.preview', ['tenant' => $account, 'document' => $document]);

    $preview = $this->get($route($this->account))->assertOk()->assertHeader('Content-Type', 'image/png');
    expect($preview->headers->get('Cache-Control'))->toContain('private', 'no-store')->not->toContain('public');
    asAWebRequest();
    $this->get($route(Account::factory()->create()))->assertNotFound();
    $this->actingAs(superAdminOf($this->account))->get($route($this->account))->assertNotFound();
    $this->actingAs($user)->travel(25)->hours();
    $this->get($route($this->account))->assertNotFound();
    $this->artisan('invoice-benchmark:prune')->assertSuccessful();
    $this->assertModelMissing($document);
    Storage::disk('local')->assertMissing($document->path());
});

it('renders private previews of both pages of a synthetic PDF', function () {
    if (! (new ExecutableFinder)->find('pdfinfo') || ! (new ExecutableFinder)->find('pdftoppm')) {
        $this->markTestSkipped('Poppler is required for local PDF inspection and previews.');
    }
    Storage::fake('local');
    config(['invoice-benchmark.enabled' => true]);
    $user = actingAsAdmin();
    $benchmark = app(InvoiceBenchmarkService::class);
    $document = $benchmark->upload(InvoiceBenchmarkFixtures::file('multirate'), $user->id, $this->account->id);
    $url = route('filament.admin.invoice-benchmark.preview', ['tenant' => $this->account, 'document' => $document]);

    expect($document->dimensions)->toBe(['pages' => 2]);
    $this->get($url.'?page=1')->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->get($url.'?page=2')->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->get($url.'?page=3')->assertNotFound();
});

it('accepts an invoice upload through the page and detects an exact duplicate', function () {
    Storage::fake('local');
    config(['invoice-benchmark.enabled' => true]);
    actingAsAdmin();
    $this->withoutVite();
    $path = resource_path('fixtures/invoice-benchmark/standard-qr.png');
    $upload = fn () => UploadedFile::fake()->createWithContent('test-invoice.png', file_get_contents($path));

    Livewire::test(InvoiceAiBenchmark::class)
        ->set('upload', $upload())
        ->call('uploadDocument')
        ->assertHasNoErrors()
        ->assertSee('Original document')
        ->set('upload', $upload())
        ->call('uploadDocument')
        ->assertHasNoErrors();

    $this->assertDatabaseCount('invoice_benchmark_documents', 1);
});

it('records a connection failure without retrying a potentially billable request', function () {
    Storage::fake('local');
    $user = actingAsAdmin();
    config(['invoice-benchmark.enabled' => true, 'invoice-benchmark.live' => true, 'invoice-benchmark.project' => 'contiu-test']);
    $this->mock(GoogleAccessTokenProvider::class)->shouldReceive('token')->once()->andReturn('fake-token');
    Http::preventStrayRequests();
    Http::fake(['https://aiplatform.eu.rep.googleapis.com/v1/projects/contiu-test/locations/eu/interactions' => Http::failedConnection()]);
    $benchmark = app(InvoiceBenchmarkService::class);
    $document = $benchmark->upload(InvoiceBenchmarkFixtures::file('standard-qr'), $user->id, $this->account->id);

    $run = $benchmark->queue($document, 'ai')->refresh();

    expect($run->status)->toBe('failed');
    $attempt = DB::table('invoice_benchmark_requests')->where('run_id', $run->id)->sole();
    expect($attempt->duration_ms)->toBeInt();
    expect($attempt->http_status)->toBeNull();
    expect($attempt->estimated_usd)->toBeNull();
});

it('refuses to start an upload when Livewire temporary storage is remote', function () {
    config(['invoice-benchmark.enabled' => true, 'livewire.temporary_file_upload.disk' => 's3']);
    actingAsAdmin();
    $this->withoutVite();

    Livewire::test(InvoiceAiBenchmark::class)
        ->call('_startUpload', 'upload', [['name' => 'invoice.png', 'size' => 1024, 'type' => 'image/png']], false)
        ->assertHasErrors(['upload']);
});

it('omits blank correction rows from verified data', function () {
    $verified = InvoiceData::verifiedValues([
        'invoice_number' => 'FT DEMO/001',
        'vat' => [['rate' => null, 'region' => ' ']],
        'lines' => [['reference' => null], ['description' => 'Article', 'quantity' => '0']],
    ]);

    expect($verified)->toBe(['invoice_number' => 'FT DEMO/001', 'lines' => [['description' => 'Article', 'quantity' => '0']]]);
});

it('classifies a response with only null fields and empty lists as an unsuccessful extraction', function () {
    Storage::fake('local');
    $user = actingAsAdmin();
    $output = syntheticBenchmarkOutput('unreadable');
    $output['vat'] = [];
    $output['lines'] = [];
    fakeBenchmarkVertex(json_encode($output, JSON_THROW_ON_ERROR));
    $benchmark = app(InvoiceBenchmarkService::class);
    $document = $benchmark->upload(InvoiceBenchmarkFixtures::file('unreadable'), $user->id, $this->account->id);

    $run = $benchmark->queue($document, 'ai')->refresh();

    expect($run->status)->toBe('failed');
    expect($run->result)->toBe($output);
});

it('keeps parsed QR fields available to review after the paid extraction fails', function () {
    if (! (new ExecutableFinder)->find('zbarimg')) {
        $this->markTestSkipped('zbarimg is required for local QR decoding.');
    }
    Storage::fake('local');
    $user = actingAsAdmin();
    fakeBenchmarkVertex('{malformed');
    $benchmark = app(InvoiceBenchmarkService::class);
    $document = $benchmark->upload(InvoiceBenchmarkFixtures::file('standard-qr'), $user->id, $this->account->id);

    $run = $benchmark->queue($document, 'qr_ai')->refresh();

    expect($run->status)->toBe('failed');
    expect($run->result['gross_total'])->toBe('123.00');
    expect($run->review['missing'])->toContain('supplier_name');
});
