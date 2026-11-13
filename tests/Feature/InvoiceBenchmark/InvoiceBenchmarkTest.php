<?php

use App\Filament\Pages\Dashboard;
use App\Jobs\ProcessInvoiceBenchmark as LegacyProcessInvoiceBenchmark;
use App\Models\Account;
use App\Models\User;
use App\Modules\InvoiceReader\Benchmark\Filament\InvoiceAiBenchmark;
use App\Modules\InvoiceReader\Benchmark\InvoiceBenchmarkDashboard;
use App\Modules\InvoiceReader\Benchmark\InvoiceBenchmarkFixtures;
use App\Modules\InvoiceReader\Benchmark\InvoiceBenchmarkService;
use App\Modules\InvoiceReader\Benchmark\InvoicePricingService;
use App\Modules\InvoiceReader\Benchmark\Models\InvoiceBenchmarkDocument;
use App\Modules\InvoiceReader\GoogleAccessTokenProvider;
use App\Modules\InvoiceReader\InvoiceData;
use App\Modules\InvoiceReader\InvoiceQrCodeService;
use App\Modules\InvoiceReader\InvoiceValidationService;
use App\Modules\InvoiceReader\ViesSupplierLookup;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
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

function fakeBenchmarkVertex(string $text, bool $retry = false, int $responses = 1): void
{
    config(['invoice-benchmark.enabled' => true, 'invoice-benchmark.live' => true, 'invoice-benchmark.project' => 'contiu-test']);
    test()->mock(GoogleAccessTokenProvider::class)->shouldReceive('token')->andReturn('fake-token');
    $endpoint = 'https://aiplatform.eu.rep.googleapis.com/v1/projects/contiu-test/locations/eu/interactions';
    $sequence = Http::sequence();
    if ($retry) {
        $sequence->push(['error' => ['code' => 429]], 429, ['Retry-After' => '0']);
    }
    for ($response = 0; $response < $responses; $response++) {
        $sequence->push([
            'status' => 'completed',
            'steps' => [['type' => 'model_output', 'content' => [['type' => 'text', 'text' => $text]]]],
            'usage' => ['total_input_tokens' => 200, 'total_output_tokens' => 20, 'total_thought_tokens' => 2, 'total_cached_tokens' => 10, 'total_tokens' => 222],
        ]);
    }
    Http::preventStrayRequests();
    Http::fake([$endpoint => $sequence]);
}

it('analyzes the same invoice in all three modes from Tools', function () {
    if (! (new ExecutableFinder)->find('zbarimg')) {
        $this->markTestSkipped('zbarimg is required for local QR decoding.');
    }

    Storage::fake('local');
    actingAsAdmin();
    Filament::setCurrentPanel('tools');
    $this->withoutVite();
    $output = syntheticBenchmarkOutput();
    $output['gross_total'] = '128.00';
    fakeBenchmarkVertex(json_encode($output, JSON_THROW_ON_ERROR), responses: 2);
    Http::stubUrl('https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number', Http::response([], 503));
    $file = InvoiceBenchmarkFixtures::file('standard-qr');

    $page = Livewire::test(InvoiceAiBenchmark::class)
        ->call('openView', 'analyze')
        ->set('upload', UploadedFile::fake()->createWithContent('invoice.png', file_get_contents($file->getPathname())))
        ->call('processDocument')
        ->assertHasNoErrors()
        ->assertSet('correction.gross_total', '123.00')
        ->assertSee('Sem chamadas à IA.');

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/interactions'));

    $page->set('mode', 'ai')
        ->call('processDocument')
        ->assertHasNoErrors()
        ->assertSet('correction.gross_total', '128.00')
        ->assertSee('Custo estimado da IA (USD) · 0.000088825000');

    $page->set('mode', 'qr_ai')
        ->call('processDocument')
        ->assertHasNoErrors()
        ->assertSet('correction.gross_total', '123.00')
        ->assertSee('Custo estimado da IA (USD) · 0.000088825000');

    $runs = InvoiceBenchmarkDocument::query()->sole()->runs()->get()->keyBy('mode');
    expect($runs->map->status->all())->toMatchArray(['qr' => 'succeeded', 'ai' => 'succeeded', 'qr_ai' => 'succeeded'])
        ->and($runs['qr_ai']->review['differences']['gross_total'])->toBe(['qr' => '123.00', 'ai' => '128.00']);
});

it('runs one extraction per mode, audits each actual request and does not charge again on duplicate submission', function () {
    Storage::fake('local');
    $user = actingAsAdmin();
    $this->withoutVite();
    $output = syntheticBenchmarkOutput();
    fakeBenchmarkVertex(json_encode($output, JSON_THROW_ON_ERROR), retry: true);
    $benchmark = app(InvoiceBenchmarkService::class);
    $document = $benchmark->upload(InvoiceBenchmarkFixtures::file('standard-qr'), $user->id, $this->account->id, 'standard-qr');
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

    Livewire::test(InvoiceAiBenchmark::class)
        ->call('openView', 'history')
        ->call('selectDocument', $document->id)
        ->call('openRun', $run->id)
        ->assertSee('Custo estimado da IA indisponível')
        ->assertSee('Custo estimado da IA (USD) · 0.000088825000');
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
    $this->withoutVite();
    $output = syntheticBenchmarkOutput();
    $output['gross_total'] = '128.00';
    $output['supplier_nif'] = null;
    $output['vat'][0]['rate'] = null;
    fakeBenchmarkVertex(json_encode($output, JSON_THROW_ON_ERROR));
    $benchmark = app(InvoiceBenchmarkService::class);
    $document = $benchmark->upload(InvoiceBenchmarkFixtures::file('standard-qr'), $user->id, $this->account->id, 'standard-qr');

    $local = $benchmark->queue($document, 'qr')->refresh();
    $combined = $benchmark->queue($document, 'qr_ai')->refresh();

    expect($local->qr_output)->toMatchArray(['status' => 'parsed', 'detected' => true]);
    expect($local->result)->toMatchArray(['supplier_nif' => '500000000', 'gross_total' => '123.00']);
    expect($local->result['vat'][0]['rate'])->toBe('23');
    expect($local->result)->not->toHaveKey('lines');
    expect($combined->result['supplier_nif'])->toBe('500000000');
    expect($combined->result['gross_total'])->toBe('123.00');
    expect($combined->result['vat'][0]['rate'])->toBe('23');
    expect(json_decode($combined->ai_output['text'], true)['vat'][0]['rate'])->toBeNull();
    expect($combined->review['differences']['gross_total'])->toBe(['qr' => '123.00', 'ai' => '128.00']);
    expect($combined->review['warnings'])->toContain('Net amount plus total tax differs from the gross total.');
    Http::assertSentCount(1);

    $legacy = $local->result;
    $legacy['vat'][0]['rate'] = null;
    $local->update(['result' => $legacy, 'qr_output' => [...$local->qr_output, 'data' => $legacy]]);

    $page = Livewire::test(InvoiceAiBenchmark::class)
        ->call('openView', 'history')
        ->call('selectDocument', $document->id)
        ->call('openRun', $local->id)
        ->assertSee('Sem chamadas à IA.');

    expect(array_values($page->get('correction.vat'))[0]['rate'])->toBe('23');
    expect($local->refresh()->result['vat'][0]['rate'])->toBeNull();
});

it('prefills supplier details from VIES without changing the extraction or marking it verified', function () {
    Storage::fake('local');
    $user = actingAsAdmin();
    $this->withoutVite();
    $output = syntheticBenchmarkOutput();
    $output['supplier_nif'] = 'PT 500 000 000';
    $output['supplier_address'] = 'Address printed on the invoice';
    fakeBenchmarkVertex(json_encode($output, JSON_THROW_ON_ERROR));
    $endpoint = 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number';
    Http::fake([$endpoint => Http::response([
        'countryCode' => 'PT', 'vatNumber' => '500000000', 'valid' => true,
        'name' => '  Example Legal Name, Lda.  ', 'address' => '---',
    ])]);
    $benchmark = app(InvoiceBenchmarkService::class);
    $document = $benchmark->upload(InvoiceBenchmarkFixtures::file('standard-qr'), $user->id, $this->account->id);

    $run = $benchmark->queue($document, 'ai')->refresh();

    expect($run->status)->toBe('succeeded');
    expect($run->result)->toBe($output);
    expect($run->review['vies_supplier'])->toBe(['supplier_name' => 'Example Legal Name, Lda.']);
    expect($document->refresh()->verified_at)->toBeNull();
    Http::assertSent(fn (Request $request): bool => $request->url() === $endpoint
        && $request->method() === 'POST'
        && $request->data() === ['countryCode' => 'PT', 'vatNumber' => '500000000']
        && ! $request->hasHeader('Authorization'));
    Http::assertSentCount(2);
    expect(DB::table('invoice_benchmark_requests')->count())->toBe(1);

    Livewire::test(InvoiceAiBenchmark::class)
        ->call('openView', 'history')
        ->call('selectDocument', $document->id)
        ->call('openRun', $run->id)
        ->assertSee('Dados do fornecedor consultados no VIES')
        ->assertSet('correction.supplier_name', 'Example Legal Name, Lda.')
        ->assertSet('correction.supplier_address', 'Address printed on the invoice');
});

it('keeps the extraction when VIES has no reliable supplier data', function (string $failure) {
    Storage::fake('local');
    $user = actingAsAdmin();
    $output = syntheticBenchmarkOutput();
    fakeBenchmarkVertex(json_encode($output, JSON_THROW_ON_ERROR));
    $endpoint = 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number';
    Http::fake([$endpoint => match ($failure) {
        'timeout' => Http::failedConnection(),
        'unavailable' => Http::response([], 503),
        'invalid' => Http::response(['valid' => false, 'name' => 'Unexpected Name']),
        'mismatched' => Http::response(['valid' => true, 'countryCode' => 'PT', 'vatNumber' => '000000000', 'name' => 'Unexpected Name']),
    }]);
    $benchmark = app(InvoiceBenchmarkService::class);
    $document = $benchmark->upload(InvoiceBenchmarkFixtures::file('standard-qr'), $user->id, $this->account->id);

    $run = $benchmark->queue($document, 'ai')->refresh();

    expect($run->status)->toBe('succeeded');
    expect($run->result)->toBe($output);
    expect($run->review)->not->toHaveKey('vies_supplier');
})->with(['timeout', 'unavailable', 'invalid', 'mismatched']);

it('shows customer details returned by VIES separately from the original extraction', function () {
    Storage::fake('local');
    $user = actingAsAdmin();
    $this->withoutVite();
    $output = syntheticBenchmarkOutput();
    $output['customer_nif'] = 'PT 500 000 000';
    $output['supplier_nif'] = null;
    fakeBenchmarkVertex(json_encode($output, JSON_THROW_ON_ERROR));
    $endpoint = 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number';
    Http::fake([$endpoint => Http::response([
        'countryCode' => 'PT', 'vatNumber' => '500000000', 'valid' => true,
        'name' => 'Example Customer, Lda.', 'address' => 'Rua do Cliente N 12, 1000-000 Lisboa',
    ])]);
    $benchmark = app(InvoiceBenchmarkService::class);
    $document = $benchmark->upload(InvoiceBenchmarkFixtures::file('standard-qr'), $user->id, $this->account->id);
    $run = $benchmark->queue($document, 'ai')->refresh();

    expect($run->result)->toBe($output);
    expect($run->review['vies_customer'])->toBe([
        'customer_name' => 'Example Customer, Lda.',
        'customer_address' => 'Rua do Cliente N 12, 1000-000 Lisboa',
    ]);
    Http::assertSent(fn (Request $request): bool => $request->url() === $endpoint
        && $request->data() === ['countryCode' => 'PT', 'vatNumber' => '500000000']);
    Http::assertSentCount(2);

    Livewire::test(InvoiceAiBenchmark::class)
        ->call('openView', 'history')
        ->call('selectDocument', $document->id)
        ->assertSee('Dados do cliente consultados no VIES')
        ->assertSet('correction.customer_name', 'Example Customer, Lda.')
        ->assertSet('correction.customer_address', 'Rua do Cliente N 12, 1000-000 Lisboa')
        ->assertSet('correction.customer_street', 'Rua do Cliente')
        ->assertSet('correction.customer_door_number', '12')
        ->assertSet('correction.customer_postal_code', '1000-000')
        ->assertSet('correction.customer_locality', 'Lisboa')
        ->call('saveGroundTruth')
        ->assertHasNoErrors();

    expect($document->refresh()->ground_truth['customer_door_number'])->toBe('12');
});

it('does not query VIES for an invalid or non-Portuguese supplier NIF', function (string $nif, string $country) {
    Storage::fake('local');
    $user = actingAsAdmin();
    $output = syntheticBenchmarkOutput();
    $output['supplier_nif'] = $nif;
    $output['supplier_country'] = $country;
    fakeBenchmarkVertex(json_encode($output, JSON_THROW_ON_ERROR));
    $benchmark = app(InvoiceBenchmarkService::class);
    $document = $benchmark->upload(InvoiceBenchmarkFixtures::file('standard-qr'), $user->id, $this->account->id);

    $run = $benchmark->queue($document, 'ai')->refresh();

    expect($run->status)->toBe('succeeded');
    expect($run->review)->not->toHaveKey('vies_supplier');
    Http::assertSentCount(1);
})->with([
    'invalid checksum' => ['500000001', 'PT'],
    'generic consumer number' => ['999999990', 'PT'],
    'foreign country' => ['500000000', 'ES'],
]);

it('looks up the supplier from a fiscal QR code without calling AI', function () {
    if (! (new ExecutableFinder)->find('zbarimg')) {
        $this->markTestSkipped('zbarimg is required for local QR decoding.');
    }
    Storage::fake('local');
    config(['invoice-benchmark.enabled' => true, 'invoice-benchmark.live' => false]);
    $user = actingAsAdmin();
    $this->withoutVite();
    Http::preventStrayRequests();
    Http::fake(['https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number' => Http::response([
        'countryCode' => 'PT', 'vatNumber' => '500000000', 'valid' => true,
        'name' => 'Example Legal Name, Lda.', 'address' => "Rua do Exemplo, n.º 12A, 2.º Esq.\n1000-000 Lisboa",
    ])]);
    $benchmark = app(InvoiceBenchmarkService::class);
    $document = $benchmark->upload(InvoiceBenchmarkFixtures::file('standard-qr'), $user->id, $this->account->id);

    $run = $benchmark->queue($document, 'qr')->refresh();

    expect($run->status)->toBe('succeeded');
    expect($run->result)->not->toHaveKey('supplier_name');
    expect($run->review['vies_supplier'])->toBe([
        'supplier_name' => 'Example Legal Name, Lda.',
        'supplier_address' => 'Rua do Exemplo, n.º 12A, 2.º Esq. 1000-000 Lisboa',
    ]);

    $page = Livewire::test(InvoiceAiBenchmark::class)
        ->call('openView', 'history')
        ->call('selectDocument', $document->id)
        ->call('openRun', $run->id)
        ->assertSet('correction.customer_name', 'Consumidor final')
        ->assertSet('correction.supplier_street', 'Rua do Exemplo')
        ->assertSet('correction.supplier_door_number', '12A')
        ->assertSet('correction.supplier_postal_code', '1000-000')
        ->assertSet('correction.supplier_locality', 'Lisboa')
        ->set('correction.supplier_door_number', '12B')
        ->call('saveGroundTruth')
        ->assertHasNoErrors()
        ->call('openRun', $run->id)
        ->assertSet('correction.supplier_door_number', '12B');

    expect($document->refresh()->ground_truth['supplier_door_number'])->toBe('12B');
    expect($page->get('correction.supplier_address'))->toBe('Rua do Exemplo, n.º 12A, 2.º Esq. 1000-000 Lisboa');
    $document->update(['ground_truth' => ['customer_nif' => '999999990']]);
    $page->call('openRun', $run->id)->assertSet('correction.customer_name', 'Consumidor final');
    expect($document->refresh()->ground_truth)->toBe(['customer_nif' => '999999990']);
    Http::assertSentCount(1);
    expect(DB::table('invoice_benchmark_requests')->count())->toBe(0);
});

it('splits only identifiable parts of Portuguese VIES addresses', function (string $address, array $parts) {
    expect(ViesSupplierLookup::addressParts($address))->toBe($parts);
})->with([
    'one line with abbreviated street and door' => ['Av. da República 34 1050-193 Lisboa Portugal', [
        'supplier_postal_code' => '1050-193', 'supplier_locality' => 'Lisboa',
        'supplier_street' => 'Av. da República', 'supplier_door_number' => '34',
    ]],
    'VIES door marker followed by extra address details' => ['AV LUSIADA N 100 LISBOA 1500-650 LISBOA', [
        'supplier_postal_code' => '1500-650', 'supplier_locality' => 'LISBOA',
        'supplier_street' => 'AV LUSIADA', 'supplier_door_number' => '100',
    ]],
    'explicit door without a postal code' => ['Rua do Exemplo N 100', [
        'supplier_street' => 'Rua do Exemplo', 'supplier_door_number' => '100',
    ]],
    'street without a door' => ["Rua das Flores\n4000-000 Porto", [
        'supplier_postal_code' => '4000-000', 'supplier_locality' => 'Porto', 'supplier_street' => 'Rua das Flores',
    ]],
    'street name with a number and an unclear suffix' => ['Rua 25 de Abril Lote 2 1000–000 Lisboa', [
        'supplier_postal_code' => '1000-000', 'supplier_locality' => 'Lisboa',
    ]],
    'postal code with no locality' => ['Parque Industrial 1000-000 Portugal', ['supplier_postal_code' => '1000-000']],
    'multiple postal codes' => ['1000-000 Lisboa / 4000-000 Porto', []],
    'missing postcode' => ['Parque Industrial Lote 2', []],
]);

it('fills a unique postal street or offers choices without filling the door number', function (string $party, int $status, bool $multipleStreets) {
    Storage::fake('local');
    config(['invoice-benchmark.enabled' => true, 'invoice-benchmark.live' => false, 'cache.default' => 'array']);
    actingAsAdmin();
    $this->withoutVite();
    $endpoint = 'https://geoapi.pt/cp/2495-300?json=1';
    Http::preventStrayRequests();
    Http::fake([$endpoint => Http::response([
        'CP' => '2495-300',
        'Localidade' => 'Boleiros',
        'Designação Postal' => 'FÁTIMA',
        'partes' => [
            ['Artéria' => 'Estrada de Minde', 'Porta' => '32'],
            ...($multipleStreets ? [['Artéria' => 'Travessa da Faria']] : []),
            ['Artéria' => 'Estrada de Minde'],
        ],
    ], $status)]);

    $page = Livewire::test(InvoiceAiBenchmark::class)->call('loadDemo');
    $run = InvoiceBenchmarkDocument::query()->sole()->runs()->sole();
    $run->update(['review' => [
        ...$run->review,
        'vies_'.$party => [$party.'_address' => 'Lote 2, 2495-300 Portugal'],
    ]]);

    $page->call('openView', 'history')
        ->call('selectDocument', $run->document_id)
        ->assertSet("correction.{$party}_address", 'Lote 2, 2495-300 Portugal')
        ->assertSet("correction.{$party}_postal_code", '2495-300')
        ->assertSet("correction.{$party}_door_number", null);

    if ($status === 200 && $multipleStreets) {
        $page->assertSet("correction.{$party}_locality", 'Boleiros')
            ->assertSet("correction.{$party}_street", null)
            ->assertSet("postalSuggestions.{$party}.streets", ['Estrada de Minde', 'Travessa da Faria'])
            ->assertSee('Ruas sugeridas pela GEO API PT')
            ->call('choosePostalStreet', '1', $party)
            ->assertSet("correction.{$party}_street", 'Travessa da Faria')
            ->assertSet("correction.{$party}_door_number", null)
            ->assertSet($party === 'customer' ? 'correction.supplier_street' : 'correction.customer_street', null);
    } elseif ($status === 200) {
        $page->assertSet("correction.{$party}_locality", 'Boleiros')
            ->assertSet("correction.{$party}_street", 'Estrada de Minde')
            ->assertDontSee('Ruas sugeridas pela GEO API PT');
    } else {
        $page->assertSet("correction.{$party}_locality", null)
            ->assertSet("correction.{$party}_street", null)
            ->assertDontSee('Ruas sugeridas pela GEO API PT');
    }

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->url() === $endpoint);
})->with(['supplier', 'customer'])
    ->with(['one distinct street' => [200, false], 'multiple streets' => [200, true], 'provider unavailable' => [503, false]]);

it('flags invalid dates, NIF checksums and decimal or VAT inconsistencies', function () {
    $data = array_replace(syntheticBenchmarkOutput('multirate'), ['supplier_nif' => '500000001', 'issue_date' => '2026-02-29', 'vat_amount' => '99.00', 'paid_amount' => 'EUR 10']);
    $review = app(InvoiceValidationService::class)->review($data, [], []);

    expect($review['warnings'])->toContain('Invalid Portuguese supplier NIF checksum.', 'Invalid date in issue_date.', 'VAT groups do not add up to the reported VAT amount.', 'Invalid decimal in document 1 (paid_amount).');
    app()->setLocale('pt_PT');
    expect(array_map(InvoiceValidationService::warningLabel(...), $review['warnings']))->toContain(
        'O NIF português do fornecedor é inválido.',
        'Data inválida no campo «Emissão».',
        'Valor numérico inválido em «Documento 1» no campo «Valor pago».',
    );
    expect((string) InvoiceData::decimal('23,00'))->toBe('23.00');
    expect(InvoiceData::compare('FT DEMO/OO1', 'FT DEMO/001', 'invoice_number'))->toBeFalse();
});

it('retries an unread PDF QR at higher resolution when reanalyzing the same document', function () {
    $finder = new ExecutableFinder;
    if (! $finder->find('pdfinfo') || ! $finder->find('pdftoppm') || ! $finder->find('zbarimg')) {
        $this->markTestSkipped('Poppler and zbarimg are required for PDF QR decoding.');
    }

    Storage::fake('local');
    config(['invoice-benchmark.enabled' => true, 'queue.default' => 'sync']);
    $user = actingAsAdmin();
    Http::preventStrayRequests();
    Http::fake(['https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number' => Http::response([], 503)]);
    $benchmark = app(InvoiceBenchmarkService::class);
    $pdf = app_path('Modules/InvoiceReader/resources/fixtures/small-fiscal-qr.pdf');
    $document = $benchmark->upload(new UploadedFile($pdf, 'invoice.pdf', test: true), $user->id, $this->account->id);
    $run = $document->runs()->create([
        'mode' => 'qr', 'status' => 'failed', 'qr_output' => ['status' => 'not_found', 'detected' => false, 'data' => null],
    ]);

    $retried = $benchmark->queue($document, 'qr')->refresh();

    expect($retried->id)->toBe($run->id)
        ->and($retried->status)->toBe('succeeded')
        ->and($retried->qr_output['status'])->toBe('parsed')
        ->and($retried->result['gross_total'])->toBe('123.00');
    expect($document->runs()->count())->toBe(1);
});

it('reads fiscal QR tax groups by region and rejects invalid fields', function () {
    $reader = app(InvoiceQrCodeService::class);
    $header = 'A:500000000*B:999999990*C:PT*D:FT*E:N*F:20261113*G:FT 1/0001*H:TEST-0001';
    $totals = '*N:14.90*O:124.90*Q:DEMO*R:9999';

    $parsed = $reader->parse($header.'*I1:PT*I2:10.00*I3:25.00*I4:1.50*J1:PT-AC*J7:50.00*J8:8.00*K1:PT-MA*K7:25.00*K8:5.40'.$totals);

    expect(array_column($parsed['vat'], 'region'))->toBe(['PT', 'PT', 'PT-AC', 'PT-MA']);
    expect(array_column($parsed['vat'], 'rate'))->toBe(['0', '6', '16', '22']);
    expect($parsed['invoice_number'])->toBe('FT 1/0001');
    expect($reader->parse($header.$totals.'*S:NU;124.90'))->toBe($reader->parse($header.$totals));
    expect($reader->parse($header.$totals.'*S:NU;124.90*S:duplicate'))->toBeNull();
    expect($reader->parse($header.'*I1:PT*I8:1,25'.$totals))->toBeNull();
    expect($reader->parse('https://example.org/qr'))->toBeNull();
    expect($reader->parse(str_replace('20261113', '20260230', $header).$totals))->toBeNull();
});

it('derives reduced Madeira and standard Azores VAT rates at their changeover dates', function (string $date, string $region, string $group, ?string $rate) {
    $parsed = app(InvoiceQrCodeService::class)->parse(
        "A:500000000*B:999999990*C:PT*D:FT*E:N*F:{$date}*G:FT 1/0001*H:TEST-0001*I1:{$region}*I{$group}:25.00*N:1.00*O:26.00*Q:DEMO*R:9999",
    );

    expect($parsed['vat'][0]['rate'])->toBe($rate);
})->with([
    'Madeira before the change' => ['20240930', 'PT-MA', '3', '5'],
    'Madeira after the change' => ['20241001', 'PT-MA', '3', '4'],
    'Azores before the change' => ['20210630', 'PT-AC', '7', '18'],
    'Azores after the change' => ['20210701', 'PT-AC', '7', '16'],
    'foreign VAT region' => ['20261113', 'FR', '7', null],
]);

it('calculates regional pricing from raw counts and leaves insufficient usage unknown', function () {
    $pricing = app(InvoicePricingService::class);
    $usage = ['total_input_tokens' => 200, 'total_output_tokens' => 20, 'total_thought_tokens' => 2, 'total_cached_tokens' => 10, 'total_tokens' => 222];

    expect($pricing->estimate($usage, config('invoice-benchmark.pricing')))->toBe('0.000088825000');
    expect($pricing->estimate([...$usage, 'total_tokens' => 221], config('invoice-benchmark.pricing')))->toBeNull();
});

it('runs demo and verifies ground truth from the page while preserving original extraction', function () {
    Storage::fake('local');
    config(['invoice-benchmark.enabled' => true, 'invoice-benchmark.live' => false, 'queue.default' => 'database']);
    actingAsAdmin();
    $this->withoutVite();
    Http::preventStrayRequests();

    Livewire::test(InvoiceAiBenchmark::class)
        ->assertSee('Experimentar um exemplo')
        ->assertSee('Histórico e estatísticas')
        ->assertSee('Fotografia de fatura com pouca qualidade')
        ->call('loadDemo')
        ->assertHasNoErrors()
        ->assertSee('Documento original')
        ->assertSee('Leitura simulada')
        ->assertSee('Valor correto')
        ->assertSee('1 campo incorreto')
        ->assertSee('Valor incorreto')
        ->assertSee('Em falta')
        ->assertSee('FT DEMO/OO1')
        ->assertSee('FT DEMO/001')
        ->assertSee('500000000')
        ->assertSee('23%')
        ->assertSee('Artigo de teste')
        ->assertSee('Fatura de exemplo')
        ->assertSee('Rever e editar valores')
        ->assertSee('Fatura · revisão')
        ->assertSee('Leitura original')
        ->assertSee('Usar os valores corretos do exemplo')
        ->assertDontSee('Analisar documento')
        ->set('correction.invoice_number', 'FT DEMO/001')
        ->call('saveGroundTruth')
        ->assertHasNoErrors()
        ->call('openView', 'history')
        ->assertSee('Análises verificadas · 1');

    $document = InvoiceBenchmarkDocument::query()->firstOrFail();
    expect($document->ground_truth['invoice_number'])->toBe('FT DEMO/001');
    expect($document->runs()->firstOrFail()->result['invoice_number'])->toBe('FT DEMO/OO1');
    expect(DB::table('invoice_benchmark_requests')->count())->toBe(0);
    $this->assertDatabaseCount('jobs', 0);
});

it('saves the corrected invoice lines in the order edited on the invoice sheet', function () {
    Storage::fake('local');
    config(['invoice-benchmark.enabled' => true]);
    actingAsAdmin();
    $this->withoutVite();

    $page = Livewire::test(InvoiceAiBenchmark::class)->call('loadDemo');
    $original = array_key_first($page->get('correction.lines'));
    $page->call('addCorrectionRow', 'lines')->call('addCorrectionRow', 'vat');
    $new = array_key_last($page->get('correction.lines'));
    $vatRow = array_key_last($page->get('correction.vat'));
    $page->set("correction.lines.{$new}.description", 'Second sample item')
        ->set("correction.lines.{$new}.line_total", '22.45')
        ->assertSeeHtml('<option value="standard">Taxa normal</option>')
        ->assertSeeHtml('<option value="reduced">Taxa reduzida</option>')
        ->assertSeeHtml('<option value="intermediate">Taxa intermédia</option>')
        ->assertSeeHtml('<option value="exempt">Isento</option>')
        ->set("correction.vat.{$vatRow}.category", 'exempt')
        ->call('moveCorrectionRow', 'lines', $new, -1)
        ->call('removeCorrectionRow', 'lines', $original)
        ->call('saveGroundTruth')
        ->assertHasNoErrors();

    $document = InvoiceBenchmarkDocument::query()->sole();
    expect($document->ground_truth['lines'])->toBe([['description' => 'Second sample item', 'line_total' => '22.45']])
        ->and($document->ground_truth['vat'])->toHaveCount(2)
        ->and($document->ground_truth['vat'][1]['category'])->toBe('exempt')
        ->and($document->runs()->sole()->result['lines'][0]['description'])->toBe('Artigo de teste');
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

it('only opens the tools panel to super admins and keeps the benchmark out of the app panel', function () {
    config(['invoice-benchmark.enabled' => false]);
    $this->withoutVite();
    $admin = superAdminOf($this->account);
    $route = route('filament.tools.pages.invoice-ai-benchmark', ['tenant' => $this->account]);
    asAWebRequest();

    $this->get($route)->assertRedirect(route('filament.tools.auth.login'))
        ->assertSessionHas('url.intended', $route);
    $this->get(route('filament.tools.auth.login'))->assertRedirect(route('filament.admin.auth.login'));
    $this->actingAs($admin)->get($route)->assertForbidden();
    config(['invoice-benchmark.enabled' => true]);
    $owner = ownerOf($this->account);
    $dashboard = Dashboard::getUrl(panel: 'admin', tenant: $this->account);
    $toolsHome = route('filament.tools.home', ['tenant' => $this->account]);
    $this->actingAs($owner)->get($route)->assertForbidden();
    $this->get($dashboard)->assertOk()->assertDontSee($toolsHome, escape: false);

    $accountant = User::factory()->create();
    $this->account->users()->attach($accountant, ['role' => 'accountant']);
    $this->actingAs($accountant)->get($route)->assertForbidden();

    $this->actingAs($admin)->get($dashboard)->assertOk()->assertSee($toolsHome, escape: false);
    $this->get(route('filament.tools.tenant'))->assertRedirect($toolsHome);
    $this->get($toolsHome)->assertRedirect($route);
    $this->get($route)->assertOk()->assertSee('Teste de leitura de faturas')->assertSee($dashboard, escape: false);
    expect(Route::has('filament.admin.pages.invoice-ai-benchmark'))->toBeFalse();
    expect(Route::has('filament.admin.invoice-benchmark.preview'))->toBeFalse();
});

it('uses the Contiu layout in Tools without showing the account switcher', function () {
    config(['invoice-benchmark.enabled' => true]);
    $this->withoutVite();
    actingAsAdmin();
    Account::factory()->create();
    asAWebRequest();

    $response = $this->get(InvoiceAiBenchmark::getUrl(panel: 'tools', tenant: $this->account))
        ->assertOk()
        ->assertSee('name="filament-appearance"', escape: false)
        ->assertSee('brand/contiu/contiu-logo-white.svg', escape: false)
        ->assertSee('fi-body-has-sidebar-collapsible-on-desktop', escape: false)
        ->assertSee('class="fap-mobile-nav"', escape: false)
        ->assertDontSee('fi-tenant-menu-trigger', escape: false);

    preg_match('/<meta name="filament-appearance" content="([^"]*)">/', $response->getContent(), $appearance);
    expect(explode(' ', $appearance[1]))->not->toContain('search');
});

it('serves private previews to the owner in the correct tenant and removes expired records and files', function () {
    Storage::fake('local');
    config(['invoice-benchmark.enabled' => true]);
    $user = actingAsAdmin();
    $benchmark = app(InvoiceBenchmarkService::class);
    $document = $benchmark->upload(InvoiceBenchmarkFixtures::file('standard-qr'), $user->id, $this->account->id);
    $route = fn (Account $account): string => route('filament.tools.invoice-benchmark.preview', ['tenant' => $account, 'document' => $document]);

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
    $url = route('filament.tools.invoice-benchmark.preview', ['tenant' => $this->account, 'document' => $document]);

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
    $path = InvoiceBenchmarkFixtures::file('standard-qr')->getPathname();
    $upload = fn () => UploadedFile::fake()->createWithContent('test-invoice.png', file_get_contents($path));

    Livewire::test(InvoiceAiBenchmark::class)
        ->call('openView', 'analyze')
        ->set('upload', $upload())
        ->call('uploadDocument')
        ->assertHasNoErrors()
        ->assertSee('Documento original')
        ->set('upload', $upload())
        ->call('uploadDocument')
        ->assertHasNoErrors();

    $this->assertDatabaseCount('invoice_benchmark_documents', 1);
});

it('uploads and queues a document from the analysis form in one action', function () {
    Storage::fake('local');
    config(['invoice-benchmark.enabled' => true, 'invoice-benchmark.live' => false, 'queue.default' => 'database']);
    actingAsAdmin();
    $this->withoutVite();
    $file = UploadedFile::fake()->createWithContent('invoice.png', file_get_contents(InvoiceBenchmarkFixtures::file('standard-qr')->getPathname()));

    $page = Livewire::test(InvoiceAiBenchmark::class)
        ->call('openView', 'analyze')
        ->assertDontSee('Mostrar exemplo')
        ->set('upload', $file)
        ->call('processDocument')
        ->assertHasNoErrors()
        ->assertSee('Documento original')
        ->assertSee('A aguardar análise');

    $run = InvoiceBenchmarkDocument::query()->sole()->runs()->sole();
    expect($run->mode)->toBe('qr');
    expect($run->status)->toBe('queued');
    $this->assertDatabaseCount('jobs', 1);

    $run->update(['status' => 'succeeded', 'result' => ['invoice_number' => 'FT DEMO/001']]);

    $page->call('refreshAnalysis')
        ->assertSee('Análise concluída')
        ->assertSee('N.º da fatura')
        ->assertSet('correction.invoice_number', 'FT DEMO/001')
        ->call('openView', 'history')
        ->call('selectDocument', $run->document_id)
        ->assertSee('Análise concluída')
        ->call('analyzeSelected')
        ->assertSee('Substituir documento (opcional)')
        ->assertDontSee('Análise concluída');
});

it('processes an invoice job queued under its original class name', function () {
    Storage::fake('local');
    config(['invoice-benchmark.enabled' => true, 'queue.default' => 'database']);
    $user = actingAsAdmin();
    $document = app(InvoiceBenchmarkService::class)->upload(
        InvoiceBenchmarkFixtures::file('standard-qr'), $user->id, $this->account->id, 'standard-qr',
    );
    $run = $document->runs()->create(['mode' => 'demo', 'status' => 'queued']);

    Queue::connection('database')->push(new LegacyProcessInvoiceBenchmark($run->id));

    $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();

    expect($run->refresh()->status)->toBe('succeeded')
        ->and($run->result['invoice_number'])->toBe('FT DEMO/001');
    $this->assertDatabaseCount('jobs', 0);
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
    Http::fake(['https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number' => Http::failedConnection()]);
    $benchmark = app(InvoiceBenchmarkService::class);
    $document = $benchmark->upload(InvoiceBenchmarkFixtures::file('standard-qr'), $user->id, $this->account->id);

    $run = $benchmark->queue($document, 'qr_ai')->refresh();

    expect($run->status)->toBe('failed');
    expect($run->result['gross_total'])->toBe('123.00');
    expect($run->review['missing'])->toContain('supplier_name');
    expect($run->review)->not->toHaveKey('vies_supplier');
});
