<?php

use App\Enums\FiscalDocumentType;
use App\Exceptions\AccountIsReadOnly;
use App\Exceptions\InvoiceXpressException;
use App\Filament\Pages\IntegrationsPage;
use App\Filament\Pages\InvoiceXpressImport as InvoiceXpressPage;
use App\Filament\Pages\InvoiceXpressSettingsPage;
use App\Models\Account;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Models\InvoiceXpressConfig;
use App\Models\InvoiceXpressSnapshot;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\WithholdingRecord;
use App\Support\CurrentAccount;
use App\Support\DocumentedTurnover;
use App\Support\InvoiceXpressClient;
use App\Support\InvoiceXpressImport;
use Filament\Facades\Filament;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

pest()->group('invoicexpress');

beforeEach(function () {
    Http::preventStrayRequests();
});

function invoiceXpressFixture(array $overrides = []): array
{
    return array_replace([
        'id' => 7345,
        'status' => 'final',
        'type' => 'Invoice',
        'sequence_number' => '10/FT',
        'inverted_sequence_number' => 'FT/10',
        'date' => '02/11/2026',
        'before_taxes' => 100.01,
        'taxes' => 23,
        'total' => 123.01,
        'currency' => 'Euro',
        'client' => ['id' => 93, 'name' => 'Cliente A', 'fiscal_id' => '501234560'],
    ], $overrides);
}

it('stores encrypted tenant credentials and validates the provider host before a request', function () {
    $config = InvoiceXpressConfig::store('Contiu-Test', 'fake-key+private');
    expect($config->subdomain)->toBe('contiu-test')
        ->and($config->api_key)->toBe('fake-key+private')
        ->and($config->toArray())->not->toHaveKey('api_key');
    expect(DB::table('invoice_xpress_configs')->value('api_key'))->not->toContain('fake-key+private');

    expect(fn () => InvoiceXpressConfig::store('evil.test', 'fake-key'))->toThrow(ValidationException::class);
    expect(fn () => app(InvoiceXpressClient::class)->request('GET', '//other.invalid/invoices.json'))->toThrow(InvoiceXpressException::class);
    expect(fn () => app(InvoiceXpressClient::class)->request('GET', '/invoices.json', ['api_key[]' => 'other']))->toThrow(InvoiceXpressException::class);

    CurrentAccount::run(Account::factory()->create(), fn () => expect(InvoiceXpressConfig::current())->toBeNull());
    Http::assertNothingSent();
})->group('integration');

it('requests one bounded page using documented repeated filters and query authentication', function () {
    InvoiceXpressConfig::store('contiu-test', 'fake+key&encoded');
    Http::fake(['https://contiu-test.app.invoicexpress.com/*' => Http::response([
        'invoices' => [invoiceXpressFixture()],
        'pagination' => ['total_entries' => 41, 'per_page' => 20, 'current_page' => 2, 'total_pages' => 3],
    ])]);

    $result = app(InvoiceXpressClient::class)->listInvoices('2026-11-01', '2026-11-30', 2, true);

    expect($result['invoices'][0]['id'])->toBe(7345)
        ->and($result['totalPages'])->toBe(3)
        ->and($result['subdomain'])->toBe('contiu-test');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_contains($request->url(), '/invoices.json?')
        && str_contains($request->url(), 'type%5B%5D=Invoice&type%5B%5D=InvoiceReceipt')
        && str_contains($request->url(), 'type%5B%5D=DebitNote')
        && ! str_contains($request->url(), 'status%5B%5D=')
        && str_contains($request->url(), 'date%5Bfrom%5D=2026-11-01')
        && str_contains($request->url(), 'date%5Bto%5D=2026-11-30')
        && str_contains($request->url(), 'api_key=fake%2Bkey%26encoded')
        && str_contains($request->url(), 'page=2')
        && str_contains($request->url(), 'per_page=20')
        && str_contains($request->url(), 'archived=true'));
    expect(fn () => app(InvoiceXpressClient::class)->listInvoices('2020-01-01', '2026-11-30'))->toThrow(ValidationException::class);
    expect(fn () => app(InvoiceXpressClient::class)->listInvoices('2026-11-01', '2026-11-30', 26))->toThrow(ValidationException::class);
    Http::assertSentCount(1);
})->group('integration');

it('provides the shared JSON client contract for an idempotent E5 request using a fake', function () {
    config(['services.invoicexpress.catalog_safe_accounts' => ['contiu-test']]);
    InvoiceXpressConfig::store('contiu-test', 'fake-key');
    Http::fake(['https://contiu-test.app.invoicexpress.com/*' => Http::response(['invoice' => invoiceXpressFixture()], 201)]);

    $result = app(InvoiceXpressClient::class)->request('POST', '/invoices.json', payload: [
        'invoice' => ['date' => '02/11/2026'],
        'proprietary_uid' => 'stable-test-id',
    ]);

    expect($result['invoice']['id'])->toBe(7345);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->hasHeader('Content-Type', 'application/json')
        && $request['proprietary_uid'] === 'stable-test-id');
})->group('integration');

it('blocks writes through the shared transport for every account without catalogue validation', function (string $method, string $path) {
    InvoiceXpressConfig::store('contiu-test', 'fake-key');
    config(['services.invoicexpress.catalog_safe_accounts' => ['another-account']]);
    Http::fake(['*/'.$path.'?*' => Http::response(['invoice' => invoiceXpressFixture()])]);

    expect(fn () => app(InvoiceXpressClient::class)->request($method, '/'.$path))
        ->toThrow(InvoiceXpressException::class, 'preserva o catálogo');

    Http::assertNothingSent();
})->with([
    ['POST', 'invoices.json'],
    ['POST', 'documents/7345/partial_payments.json'],
    ['PUT', 'credit_notes/7345/change-state.json'],
])->group('integration');

it('records an imported debit note as a positive billing correction, independently of cash', function () {
    InvoiceXpressConfig::store('contiu-test', 'fake-key');
    $import = app(InvoiceXpressImport::class);
    $import->confirmInvoice(invoiceXpressFixture());
    $note = ['debit_note' => invoiceXpressFixture([
        'id' => 7346, 'type' => 'DebitNote', 'inverted_sequence_number' => 'ND/10',
        'before_taxes' => 20, 'taxes' => 4.60, 'total' => 24.60,
    ])];

    $first = $import->confirmInvoice($note);

    expect($import->confirmInvoice($note)->id)->toBe($first->id)
        ->and($first->type)->toBe(FiscalDocumentType::DebitNote)
        ->and($first->total_amount)->toBe(2460)
        ->and(DocumentedTurnover::forYear(2026)->billedNet)->toBe(12001)
        ->and(DocumentedTurnover::forYear(2026)->received)->toBe(0);
    Http::assertNothingSent();
})->group('integration');

it('rejects debit note totals that would subtract from documented billing or cannot be reconciled', function (array $amounts) {
    InvoiceXpressConfig::store('contiu-test', 'fake-key');
    $debit = invoiceXpressFixture($amounts + ['type' => 'DebitNote']);

    expect(fn () => app(InvoiceXpressImport::class)->confirmInvoice($debit))->toThrow(ValidationException::class);
    expect(FiscalDocument::query()->exists())->toBeFalse();
})->with([
    [['before_taxes' => -100, 'taxes' => -23, 'total' => -123]],
    [['before_taxes' => 100, 'taxes' => 23, 'total' => 130]],
])->group('integration');

it('imports an InvoiceXpress adjustment with withholding at its fiscal gross', function (string $type) {
    InvoiceXpressConfig::store('contiu-test', 'fake-key');
    $import = app(InvoiceXpressImport::class);
    $note = invoiceXpressFixture([
        'type' => $type,
        'before_taxes' => 291.34,
        'taxes' => 67.01,
        'retention' => '11.5',
        'total' => 324.85,
    ]);

    $review = $import->reviewInvoice($note);
    $document = $import->confirmInvoice($review['invoice']);

    expect($document->total_amount)->toBe(35835)
        ->and($review['withholding']['retained'])->toBe(3350)
        ->and($import->confirmInvoice($review['invoice'])->id)->toBe($document->id)
        ->and(WithholdingRecord::query()->exists())->toBeFalse();

    expect(fn () => $import->reviewInvoice([...$note, 'total' => 324.86]))->toThrow(ValidationException::class);
})->with(['CreditNote', 'DebitNote'])->group('integration');

it('allows only creation of note drafts in the configured sandbox while finalization stays blocked', function (string $type) {
    config(['services.invoicexpress.sandbox_draft_account' => 'contiu-test']);
    InvoiceXpressConfig::store('contiu-test', 'fake-key');
    Http::fake(["https://contiu-test.app.invoicexpress.com/{$type}s.json?*" => Http::response([
        $type => ['id' => 7346, 'status' => 'draft'],
    ], 201)]);

    $client = app(InvoiceXpressClient::class);
    $payload = [$type => ['owner_invoice_id' => 7345]];
    expect($client->createSandboxDraft($type, $payload, 'contiu-test')[$type]['status'])->toBe('draft');
    expect(fn () => $client->request('PUT', "/{$type}s/7346/change-state.json", payload: [$type => ['state' => 'final']]))
        ->toThrow(InvoiceXpressException::class);
    expect(fn () => $client->createSandboxDraft($type, $payload, 'different-sandbox'))
        ->toThrow(InvoiceXpressException::class);
    Http::assertSentCount(1);
})->with(['credit_note', 'debit_note'])->group('integration');

it('returns only local failures for redirected, rejected or invalid provider responses', function (int $status, string $body, ?int $exposedStatus) {
    InvoiceXpressConfig::store('contiu-test', 'fake-private-key');
    Http::fake(['https://contiu-test.app.invoicexpress.com/*' => Http::response($body, $status, [
        'Location' => 'https://other.invalid/?api_key=fake-private-key',
    ])]);

    try {
        app(InvoiceXpressClient::class)->request('GET', '/invoices.json');
        test()->fail('Expected a sanitized provider error');
    } catch (InvoiceXpressException $exception) {
        expect($exception->status)->toBe($exposedStatus)
            ->and($exception->getMessage())->not->toContain('fake-private-key', 'https://', 'api_key')
            ->and($exception->getPrevious())->toBeNull();
    }
    Http::assertSentCount(1);
})->with([
    'redirect' => [302, 'private content fake-private-key', 302],
    'invalid credential' => [401, 'private content fake-private-key', 401],
    'request limit' => [429, 'private content fake-private-key', 429],
    'HTML success' => [200, '<p>private content fake-private-key</p>', null],
])->group('integration');

it('registers a final invoice once and requires the previous source hash to apply a correction', function () {
    InvoiceXpressConfig::store('contiu-test', 'fake-key');
    $import = app(InvoiceXpressImport::class);
    $invoice = invoiceXpressFixture(['permalink' => 'https://other.invalid/?api_key=do-not-store']);
    $review = $import->reviewInvoice(['invoice' => $invoice]);
    expect($review['state'])->toBe('new');
    expect(FiscalDocument::query()->count())->toBe(0);

    $first = $import->confirmInvoice(['invoice' => $invoice]);
    $same = $import->confirmInvoice(array_replace($invoice, ['status' => 'settled', 'total' => '123.01']));
    $change = array_replace($invoice, ['status' => 'canceled']);
    $changedReview = $import->reviewInvoice($change);

    expect($same->id)->toBe($first->id)
        ->and($first->number)->toBe('FT/10')
        ->and($first->net_amount)->toBe(10001)
        ->and($first->vat_amount)->toBe(2300)
        ->and($first->total_amount)->toBe(12301)
        ->and($first->currency)->toBe('EUR')
        ->and($first->operation_date)->toBeNull()
        ->and($changedReview['state'])->toBe('changed');
    expect(fn () => $import->confirmInvoice($change))->toThrow(ValidationException::class);
    expect($first->fresh()->status->value)->toBe('active');

    $corrected = $import->confirmInvoice($change, $review['source_hash']);
    expect($corrected->id)->toBe($first->id)
        ->and($corrected->status->value)->toBe('voided')
        ->and(InvoiceXpressSnapshot::query()->count())->toBe(2)
        ->and(InvoiceXpressSnapshot::query()->first()->toJson())->not->toContain('do-not-store');
    expect(fn () => $import->confirmInvoice($invoice, $review['source_hash']))->toThrow(ValidationException::class);
    Http::assertNothingSent();
})->group('integration');

it('isolates the remote identity by tenant and blocks local number collisions', function () {
    InvoiceXpressConfig::store('contiu-test', 'fake-key');
    $import = app(InvoiceXpressImport::class);
    $first = $import->confirmInvoice(invoiceXpressFixture());
    $other = Account::factory()->create();
    CurrentAccount::run($other, function () use ($first, $import): void {
        InvoiceXpressConfig::store('contiu-test', 'fake-other-key');
        $invoice = invoiceXpressFixture();
        expect($import->reviewInvoice($invoice)['state'])->toBe('new');
        $created = $import->confirmInvoice($invoice);
        expect($created->id)->not->toBe($first->id);
        $collision = invoiceXpressFixture(['id' => 8000]);
        expect($import->reviewInvoice($collision)['state'])->toBe('conflict');
        expect(fn () => $import->confirmInvoice($collision))->toThrow(ValidationException::class);
    });
})->group('integration');

it('rejects invalid provider documents without creating a fiscal record', function (array $changes) {
    InvoiceXpressConfig::store('contiu-test', 'fake-key');
    expect(fn () => app(InvoiceXpressImport::class)->confirmInvoice(invoiceXpressFixture($changes)))->toThrow(ValidationException::class);
    expect(FiscalDocument::query()->count())->toBe(0);
})->with([
    'draft' => [['status' => 'draft']],
    'foreign totals' => [['currency' => 'USD']],
    'invalid date' => [['date' => '31/02/2026']],
    'sub-cent numeric amount' => [['total' => 123.01000000000002]],
    'withholding cash differs by one cent' => [['retention' => '11.5', 'total' => 111.52]],
    'missing withholding for a net total' => [['total' => 111.51]],
])->group('integration');

it('reviews and imports the gross of a final invoice receipt with withholding without recording a payment', function () {
    actingAsAdmin();
    InvoiceXpressConfig::store('contiu-test', 'fake-key');
    $invoice = invoiceXpressFixture([
        'type' => 'InvoiceReceipt', 'status' => 'settled', 'inverted_sequence_number' => 'FR/10',
        'before_taxes' => 291.34, 'taxes' => 67.01, 'retention' => 11.5, 'total' => 324.85,
    ]);
    Http::fake(['https://contiu-test.app.invoicexpress.com/*' => Http::response([
        'invoices' => [$invoice],
        'pagination' => ['current_page' => 1, 'total_pages' => 1],
    ])]);
    $import = app(InvoiceXpressImport::class);
    $review = $import->reviewInvoice($invoice);
    expect($review['after']['total_amount'])->toBe(35835)
        ->and($review['withholding'])->toBe(['basisPoints' => 1150, 'gross' => 35835, 'retained' => 3350, 'cash' => 32485]);

    $page = Livewire::test(InvoiceXpressPage::class)
        ->set('from', '2026-11-01')->set('to', '2026-11-30')->call('preview')
        ->assertSee('Bruto')->assertSee('358,35')
        ->assertSee('Retenção')->assertSee('33,50')
        ->assertSee('Líquido')->assertSee('324,85');
    $row = $page->get('rows')[0];
    $page->callAction('importDocument', arguments: ['index' => 0, 'review' => $row['review_key']])->assertHasNoActionErrors();

    expect(FiscalDocument::sole()->total_amount)->toBe(35835)
        ->and(InvoiceXpressSnapshot::sole()->source['withholding'])->toBe($review['withholding'])
        ->and($import->reviewInvoice($invoice)['state'])->toBe('same');
    $this->assertDatabaseEmpty('payments');
    $this->assertDatabaseEmpty('withholding_records');
})->group('ui');

it('previews only the requested period and waits for individual confirmation', function () {
    actingAsAdmin();
    InvoiceXpressConfig::store('contiu-test', 'fake-key');
    Http::fake(['https://contiu-test.app.invoicexpress.com/*' => Http::response([
        'invoices' => [invoiceXpressFixture(['client' => ['name' => '<script>unsafe</script>']]), invoiceXpressFixture(['id' => 7346, 'date' => '02/10/2026', 'inverted_sequence_number' => 'FT/11'])],
        'pagination' => ['total_entries' => 2, 'per_page' => 20, 'current_page' => 1, 'total_pages' => 1],
    ])]);

    $page = Livewire::test(InvoiceXpressPage::class)
        ->set('from', '2026-11-01')
        ->set('to', '2026-11-30')
        ->call('preview')
        ->assertSee('FT/10')
        ->assertDontSee('FT/11')
        ->assertDontSeeHtml('<script>unsafe</script>');
    expect(FiscalDocument::query()->count())->toBe(0);

    $page->callAction('importDocument', arguments: ['index' => 0, 'review' => $page->get('rows')[0]['review_key']])->assertHasNoActionErrors();
    expect(FiscalDocument::query()->sole()->number)->toBe('FT/10');
    Http::assertSentCount(1);
})->group('ui');

it('drops the original connection exception and refuses a different configured subdomain', function () {
    InvoiceXpressConfig::store('contiu-test', 'fake-private-key');
    Http::fake(fn () => throw new ConnectionException('Failed: https://contiu-test.app.invoicexpress.com/?api_key=fake-private-key'));
    $client = app(InvoiceXpressClient::class);

    try {
        $client->request('GET', '/invoices.json');
        test()->fail('Expected a sanitized connection failure');
    } catch (InvoiceXpressException $exception) {
        expect($exception->status)->toBeNull()
            ->and($exception->getPrevious())->toBeNull()
            ->and($exception->getMessage())->not->toContain('fake-private-key', 'https://', 'api_key');
    }

    Http::fake(['https://contiu-test.app.invoicexpress.com/*' => Http::response(['invoice' => invoiceXpressFixture()])]);
    expect(fn () => $client->request('GET', '/invoices.json', expectedSubdomain: 'previous-account'))
        ->toThrow(InvoiceXpressException::class);
    Http::assertNothingSent();
})->group('integration');

it('refuses a stale correction when another tab edits the fiscal record or changes the provider account', function () {
    InvoiceXpressConfig::store('contiu-test', 'fake-key');
    $import = app(InvoiceXpressImport::class);
    $document = $import->confirmInvoice(invoiceXpressFixture());
    $correction = invoiceXpressFixture(['status' => 'canceled']);
    $review = $import->reviewInvoice($correction);
    $document->update(['party_name' => 'Local edit']);

    expect(fn () => $import->confirmInvoice($correction, $review['current_hash'], 'contiu-test', $review['local_hash']))
        ->toThrow(ValidationException::class);
    expect($document->fresh()->party_name)->toBe('Local edit');

    InvoiceXpressConfig::store('other-provider-account', 'fake-key');
    expect(fn () => $import->confirmInvoice(invoiceXpressFixture(), expectedSubdomain: 'contiu-test'))
        ->toThrow(ValidationException::class);
    expect(FiscalDocument::query()->count())->toBe(1);
})->group('integration');

it('does not apply a modal for an invoice after the preview has switched to another page', function () {
    actingAsAdmin();
    InvoiceXpressConfig::store('contiu-test', 'fake-key');
    Http::fakeSequence('https://contiu-test.app.invoicexpress.com/*')
        ->push([
            'invoices' => [invoiceXpressFixture()],
            'pagination' => ['total_entries' => 21, 'per_page' => 20, 'current_page' => 1, 'total_pages' => 2],
        ])->push([
            'invoices' => [invoiceXpressFixture(['id' => 7346, 'inverted_sequence_number' => 'FT/11'])],
            'pagination' => ['total_entries' => 21, 'per_page' => 20, 'current_page' => 2, 'total_pages' => 2],
        ]);

    $page = Livewire::test(InvoiceXpressPage::class)
        ->set('from', '2026-11-01')
        ->set('to', '2026-11-30')
        ->call('preview');
    $row = $page->get('rows')[0];

    $page->mountAction('importDocument', ['index' => 0, 'review' => $row['review_key']])
        ->call('movePage', 1)
        ->assertSet('previewScope.page', 2)
        ->callMountedAction();

    expect(FiscalDocument::query()->count())->toBe(0);
    Http::assertSentCount(2);
})->group('ui');

it('requires a fresh import confirmation after another lookup returns the same document', function () {
    actingAsAdmin();
    InvoiceXpressConfig::store('contiu-test', 'fake-key');
    Http::preventStrayRequests();
    Http::fake(['https://contiu-test.app.invoicexpress.com/*' => Http::response([
        'invoices' => [invoiceXpressFixture()],
        'pagination' => ['current_page' => 1, 'total_pages' => 1],
    ])]);

    $page = Livewire::test(InvoiceXpressPage::class)
        ->update(
            calls: [['method' => 'preview', 'params' => []]],
            updates: ['from' => '2026-11-01', 'to' => '2026-11-30'],
        )
        ->assertSee('FT/10');

    $page->mountAction('importDocument', ['index' => 0, 'review' => $page->get('rows')[0]['review_key']])
        ->update(
            calls: [['method' => 'preview', 'params' => []]],
            updates: ['archived' => true],
        )
        ->assertSee('FT/10')
        ->callMountedAction();

    $this->assertDatabaseEmpty('fiscal_documents');
    Http::assertSentCount(2);
})->group('ui');

it('lets the tenant owner configure the connection without returning the key to the page', function () {
    $owner = ownerOf($this->account);
    $other = Account::factory()->create();
    asAWebRequest();
    $this->actingAs($owner)
        ->get(InvoiceXpressPage::getUrl(panel: 'admin', tenant: $other))
        ->assertNotFound();
    $this->get(InvoiceXpressPage::getUrl(panel: 'admin', tenant: $this->account))
        ->assertOk()->assertSee(IntegrationsPage::getUrl(panel: 'admin', tenant: $this->account));

    CurrentAccount::set($this->account);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->account);

    Livewire::test(InvoiceXpressSettingsPage::class)
        ->callAction('configure', ['subdomain' => 'contiu-test', 'api_key' => 'fake-private-key'])
        ->assertHasNoActionErrors()
        ->mountAction('configure')
        ->assertSchemaStateSet(['subdomain' => 'contiu-test', 'api_key' => null])
        ->assertDontSee('fake-private-key');
    Http::assertNothingSent();
})->group('ui');

it('previews a cancellation as a correction before changing the stored document', function () {
    actingAsAdmin();
    InvoiceXpressConfig::store('contiu-test', 'fake-key');
    $document = app(InvoiceXpressImport::class)->confirmInvoice(invoiceXpressFixture());
    Http::fake(['https://contiu-test.app.invoicexpress.com/*' => Http::response([
        'invoices' => [invoiceXpressFixture(['status' => 'canceled'])],
        'pagination' => ['total_entries' => 1, 'per_page' => 20, 'current_page' => 1, 'total_pages' => 1],
    ])]);

    $page = Livewire::test(InvoiceXpressPage::class)
        ->set('from', '2026-11-01')
        ->set('to', '2026-11-30')
        ->call('preview')
        ->assertSee('Aplicar correção')
        ->assertSee('Registo atual no Contiu');
    expect($document->fresh()->status->value)->toBe('active');

    $page->callAction('importDocument', arguments: ['index' => 0, 'review' => $page->get('rows')[0]['review_key']])
        ->assertHasNoActionErrors()
        ->assertSee('Registado');
    expect($document->fresh()->status->value)->toBe('voided')
        ->and(InvoiceXpressSnapshot::query()->count())->toBe(2);
    Http::assertSentCount(1);
})->group('ui');

it('refuses credential and fiscal changes in an expired account', function () {
    InvoiceXpressConfig::store('contiu-test', 'fake-key');
    $this->actingAs(ownerOf($this->account));
    $this->account->update(['trial_ends_at' => now()->subDay()]);

    expect(fn () => InvoiceXpressConfig::store('different', 'fake-new-key'))
        ->toThrow(AccountIsReadOnly::class);
    expect(fn () => app(InvoiceXpressImport::class)->confirmInvoice(invoiceXpressFixture()))
        ->toThrow(AccountIsReadOnly::class);
    expect(InvoiceXpressConfig::current()->subdomain)->toBe('contiu-test')
        ->and(FiscalDocument::query()->count())->toBe(0);
    Http::assertNothingSent();
})->group('integration');

it('reuses the final invoice registered by issuance between the import preview and confirmation', function () {
    actingAsAdmin();
    InvoiceXpressConfig::store('contiu-test', 'fake-key');
    $invoice = invoiceXpressFixture();
    $draft = invoiceXpressFixture(['id' => 7346, 'status' => 'draft', 'inverted_sequence_number' => 'RASCUNHO/11']);
    Http::fake(['https://contiu-test.app.invoicexpress.com/*' => Http::response([
        'invoices' => [$invoice, $draft],
        'pagination' => ['total_entries' => 2, 'per_page' => 20, 'current_page' => 1, 'total_pages' => 1],
    ])]);

    $page = Livewire::test(InvoiceXpressPage::class)
        ->set('from', '2026-11-01')
        ->set('to', '2026-11-30')
        ->call('preview')
        ->assertSee('FT/10')
        ->assertDontSee('RASCUNHO/11');
    expect($page->get('rows')[0]['state'])->toBe('new');

    $document = app(InvoiceXpressImport::class)->confirmInvoice(['invoice' => $invoice], expectedSubdomain: 'contiu-test');

    $page->callAction('importDocument', arguments: ['index' => 0, 'review' => $page->get('rows')[0]['review_key']])
        ->assertHasNoActionErrors()
        ->assertSee('Registado');
    expect(FiscalDocument::query()->sole()->id)->toBe($document->id)
        ->and(InvoiceXpressSnapshot::query()->sole()->fiscal_document_id)->toBe($document->id);
    Http::assertSentCount(1);
})->group('ui');

it('suggests a charge by fiscal identity, total and due date and only links it on explicit confirmation', function () {
    $this->travelTo(Carbon::parse('2026-11-09'));
    $this->actingAs(ownerOf($this->account));
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->account);
    InvoiceXpressConfig::store('contiu-test', 'fake-key');
    $client = Client::factory()->create(['vat_number' => 'PT501234560', 'fiscal_country_code' => 'PT']);
    $project = Project::factory()->for($client)->create();
    $charge = Receivable::factory()->for($project, 'billable')
        ->create(['amount' => 12301, 'description' => 'Manutenção de novembro', 'due_on' => '2026-11-16']);
    Receivable::factory()->for($project, 'billable')->create(['amount' => 12301, 'due_on' => '2026-12-16']);
    Http::fake(['https://contiu-test.app.invoicexpress.com/*' => Http::response([
        'invoices' => [invoiceXpressFixture(['due_date' => '16/11/2026'])],
        'pagination' => ['current_page' => 1, 'total_pages' => 1],
    ])]);

    $page = Livewire::test(InvoiceXpressPage::class)->call('preview')
        ->assertSee('Manutenção de novembro');
    $row = $page->get('rows')[0];
    expect($row['suggestions'])->toHaveKey($charge->id)->toHaveCount(1)
        ->and(FiscalDocument::query()->count())->toBe(0);

    $page->callAction('importDocument', data: ['receivable_id' => $charge->id], arguments: ['index' => 0, 'review' => $row['review_key']])
        ->assertHasNoActionErrors();

    expect(FiscalDocument::query()->sole()->links->receivable_id)->toBe($charge->id);
})->group('ui');

it('rejects another tenant or a stale charge choice and imports without a link by default', function () {
    InvoiceXpressConfig::store('contiu-test', 'fake-key');
    $import = app(InvoiceXpressImport::class);
    $invoice = invoiceXpressFixture(['due_date' => '16/11/2026']);
    $foreign = CurrentAccount::run(Account::factory()->create(), fn () => Receivable::factory()->create());
    $client = Client::factory()->create(['vat_number' => '501234560']);
    $project = Project::factory()->for($client)->create();
    $charge = Receivable::factory()->for($project, 'billable')->create(['amount' => 12301, 'due_on' => '2026-11-16']);

    expect(fn () => $import->confirmInvoice($invoice, receivableId: $foreign->id))->toThrow(ValidationException::class);
    $charge->update(['amount' => 12000]);
    expect(fn () => $import->confirmInvoice($invoice, receivableId: $charge->id))->toThrow(ValidationException::class);
    expect(FiscalDocument::query()->count())->toBe(0)
        ->and($import->confirmInvoice($invoice)->links)->toBeNull();
})->group('integration');

it('keeps ambiguous matches for review and excludes charges already covered by an invoice-receipt', function () {
    InvoiceXpressConfig::store('contiu-test', 'fake-key');
    $client = Client::factory()->create(['vat_number' => '501234560']);
    $project = Project::factory()->for($client)->create();
    $charges = Receivable::factory()->for($project, 'billable')->count(3)
        ->create(['amount' => 12301, 'due_on' => '2026-11-16']);
    $payment = Payment::factory()->for($charges[0], 'receivable')->create(['amount' => 12301]);
    FiscalDocument::factory()->create(['type' => FiscalDocumentType::InvoiceReceipt])
        ->links()->create(['payment_id' => $payment->id]);

    $import = app(InvoiceXpressImport::class);
    $invoice = invoiceXpressFixture(['due_date' => '16/11/2026']);
    $suggestions = $import->reviewInvoice($invoice)['suggestions'];

    expect(array_keys($suggestions))->toBe([$charges[1]->id, $charges[2]->id])
        ->and($import->confirmInvoice($invoice)->links)->toBeNull();
})->group('integration');

describe('Import preview summary', function () {
    beforeEach(function () {
        Http::preventStrayRequests();
        $this->travelTo(now()->setDate(2026, 11, 10));
    });

    it('counts the InvoiceXpress documents under review by what there is to do with each', function () {
        actingAsAdmin();
        InvoiceXpressConfig::store('contiu-test', 'fake-key');
        $invoice = fn (array $overrides): array => $overrides + [
            'status' => 'final', 'type' => 'Invoice', 'date' => '02/11/2026', 'currency' => 'Euro',
            'before_taxes' => 100, 'taxes' => 23, 'total' => 123,
            'client' => ['id' => 93, 'name' => 'Cliente A', 'fiscal_id' => '501234560'],
        ];
        Http::fake(['https://contiu-test.app.invoicexpress.com/*' => Http::response([
            'invoices' => [
                $invoice(['id' => 7345, 'sequence_number' => '10/FT', 'inverted_sequence_number' => 'FT/10']),
                $invoice(['id' => 7346, 'sequence_number' => '11/FT', 'inverted_sequence_number' => 'FT/11']),
            ],
            'pagination' => ['total_entries' => 2, 'per_page' => 20, 'current_page' => 1, 'total_pages' => 1],
        ])]);

        $page = Livewire::test(InvoiceXpressPage::class)
            ->assertSee('Traz os documentos emitidos e liga-os às cobranças do Contiu.')
            ->assertDontSee('Documentos encontrados')
            ->set('from', '2026-11-01')
            ->set('to', '2026-11-30')
            ->call('preview');

        expect(importScreenText($page))->toContain('Documentos encontrados 2 Página 1 de 1 Já registados 0 Sem diferenças Por importar 2 Com conflito 0 Nenhum');

        $page->callAction('importDocument', arguments: ['index' => 0, 'review' => $page->get('rows')[0]['review_key']]);

        expect(importScreenText($page))->toContain('Já registados 1 Sem diferenças Por importar 1 Com conflito 0');
        expect(FiscalDocument::query()->count())->toBe(1);
    })->group('ui');
});
