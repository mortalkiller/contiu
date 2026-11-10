<?php

use App\Actions\ManageCustomerAdvance;
use App\Enums\FiscalDocumentType;
use App\Enums\PaymentMethod;
use App\Filament\Pages\InvoiceXpressIssuePage;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Models\FiscalProfile;
use App\Models\InvoiceXpressConfig;
use App\Models\InvoiceXpressIssueAttempt;
use App\Models\InvoiceXpressSnapshot;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\Setting;
use App\Models\User;
use App\Support\InvoiceXpressImport;
use App\Support\InvoiceXpressIssue;
use App\Support\InvoiceXpressIssueSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

function invoiceXpressIssueReceivable(): Receivable
{
    $owner = actingAsAdmin();
    config(['services.invoicexpress.catalog_safe_accounts' => ['pilot']]);
    test()->travelTo(now()->setDate(2026, 11, 10));
    InvoiceXpressConfig::store('pilot', 'fake-test-credential');
    Setting::create(['key' => 'invoice_xpress_issue', 'value' => [
        'provider' => 'invoicexpress', 'user_id' => $owner->id, 'subdomain' => 'pilot',
        'item_ids' => [8], 'tax_name' => 'IVA Contiu', 'sequence_id' => 19,
    ]]);
    $client = Client::factory()->create([
        'fiscal_country_code' => 'PT', 'vat_number' => 'PT508025338', 'fiscal_party_type' => 'business',
    ]);
    $project = Project::factory()->for($client)->create(['vat_rate' => '0.2300']);

    $receivable = Receivable::factory()->for($project, 'billable')->create(['amount' => 36918, 'due_on' => '2026-12-10']);
    test()->invoiceXpressIssueReference = "Contiu #{$receivable->id}";

    return $receivable;
}

it('allows a preview but refuses to create a draft for a provider account without catalogue validation', function () {
    $receivable = invoiceXpressIssueReceivable();
    config(['services.invoicexpress.catalog_safe_accounts' => []]);
    invoiceXpressIssueFake(fn () => Http::response([], 503));
    $service = app(InvoiceXpressIssue::class);
    $preview = $service->preview($receivable->id, 42, 8, 3);

    expect(fn () => $service->confirm($receivable->id, 42, 8, 3, $preview['revision']))
        ->toThrow(ValidationException::class);

    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    $this->assertDatabaseCount('invoice_xpress_issue_attempts', 0);
});

it('refuses a new invoice for a charge already documented by a debit note', function () {
    $receivable = invoiceXpressIssueReceivable();
    FiscalDocument::factory()->create(['type' => FiscalDocumentType::DebitNote])
        ->links()->create(['receivable_id' => $receivable->id]);
    invoiceXpressIssueFake(fn () => Http::response([], 503));

    expect(fn () => app(InvoiceXpressIssue::class)->preview($receivable->id, 42, 8, 3))
        ->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

it('does not retry a pending draft creation once catalogue validation is absent', function () {
    $receivable = invoiceXpressIssueReceivable();
    invoiceXpressIssueFake(fn () => Http::response([], 503));
    $service = app(InvoiceXpressIssue::class);
    $preview = $service->preview($receivable->id, 42, 8, 3);
    $attempt = $service->confirm($receivable->id, 42, 8, 3, $preview['revision']);

    config(['services.invoicexpress.catalog_safe_accounts' => []]);
    expect(fn () => $service->recover($attempt->id))->toThrow(ValidationException::class)
        ->and($attempt->fresh()->state)->toBe(InvoiceXpressIssueAttempt::Pending);
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'))->toHaveCount(1);
});

it('loads provider choices for issuance without writing to the catalogue', function () {
    invoiceXpressIssueReceivable();
    Http::preventStrayRequests();
    Http::fake([
        '*/clients.json?*' => Http::response(['clients' => [['id' => 42, 'name' => 'Cliente da API', 'fiscal_id' => '508025338']],
            'pagination' => ['current_page' => 1, 'total_pages' => 1]]),
        '*/items.json?*' => Http::response(['items' => [['id' => 8, 'name' => 'Consultoria da API']]]),
        '*/taxes.json?*' => Http::response(['taxes' => [['name' => 'IVA Contiu', 'region' => 'PT', 'value' => 23]]]),
        '*/sequences.json?*' => Http::response(['sequences' => [
            ['id' => 19, 'serie' => 'PILOTO', 'current_invoice_sequence_id' => 19, 'current_invoice_validation_code' => 'ABCD1234'],
            ['id' => 20, 'serie' => 'Sem registo', 'current_invoice_sequence_id' => 20, 'current_invoice_validation_code' => null],
        ]]),
    ]);

    Livewire::test(InvoiceXpressIssuePage::class)
        ->call('loadCatalog')
        ->assertHasNoErrors()
        ->assertSee('Cliente da API')
        ->assertSee('Consultoria da API')
        ->assertSee('PILOTO')
        ->assertDontSee('Sem registo');

    Http::assertSentCount(4);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
});

function invoiceXpressIssueResponse(string $status = 'draft', array $overrides = []): array
{
    return ['invoice' => array_replace([
        'id' => 65, 'status' => $status, 'type' => 'Invoice', 'currency' => 'Euro', 'sequence_id' => 19,
        'date' => '10/11/2026', 'due_date' => '10/12/2026', 'reference' => test()->invoiceXpressIssueReference,
        'client' => ['id' => 42, 'name' => 'Cliente Teste', 'country' => 'Portugal', 'fiscal_id' => '508025338'],
        'before_taxes' => 300.15, 'taxes' => 69.03, 'total' => 369.18, 'discount' => 0, 'retention' => null,
        'items' => [[
            'name' => 'Consultoria', 'description' => 'Serviço', 'unit' => 'service', 'unit_price' => '100.0500',
            'quantity' => '3.0', 'discount' => 0, 'subtotal' => 300.15, 'tax_amount' => 69.03, 'total' => 369.18,
            'tax' => ['id' => 9, 'name' => 'IVA Contiu', 'value' => 23],
        ]],
        ...($status === 'final' ? ['inverted_sequence_number' => 'PILOTO/7', 'atcud' => 'ABCD1234-7'] : []),
    ], $overrides)];
}

function invoiceXpressIssueFake(Closure $invoice, string $itemPrice = '100.0500'): void
{
    Http::preventStrayRequests();
    Http::fake(function (Request $request) use ($invoice, $itemPrice) {
        return match (parse_url($request->url(), PHP_URL_PATH)) {
            '/clients/42.json' => Http::response(['client' => ['id' => 42, 'name' => 'Cliente Teste', 'country' => 'Portugal', 'fiscal_id' => '508025338']]),
            '/items/8.json' => Http::response(['item' => ['id' => 8, 'name' => 'Consultoria', 'description' => 'Serviço', 'unit' => 'service', 'unit_price' => $itemPrice,
                'tax' => ['id' => 9, 'name' => 'IVA Contiu', 'value' => 23]]]),
            '/taxes.json' => Http::response(['taxes' => [['id' => 9, 'name' => 'IVA Contiu', 'region' => 'PT', 'code' => 'NOR', 'value' => '23.0']]]),
            '/items/12.json' => Http::response(['item' => ['id' => 12, 'name' => 'Suporte', 'description' => 'Apoio', 'unit' => 'hour', 'unit_price' => '100.05',
                'tax' => ['id' => 9, 'name' => 'IVA Contiu', 'value' => 23]]]),
            '/sequences/19.json' => Http::response(['sequence' => ['id' => 19, 'serie' => 'PILOTO', 'current_invoice_sequence_id' => 19, 'current_invoice_validation_code' => 'ABCD1234',
                'current_invoice_receipt_sequence_id' => 25, 'current_invoice_receipt_validation_code' => 'FRCD1234',
                'current_simplified_invoice_sequence_id' => 22, 'current_simplified_invoice_validation_code' => 'FSCD1234']]),
            default => $invoice($request),
        };
    });
}

it('finalizes an eligible simplified service invoice using its own registered series and reference', function () {
    $receivable = invoiceXpressIssueReceivable();
    $receivable->update(['amount' => 9840]);
    $final = false;
    invoiceXpressIssueFake(function (Request $request) use (&$final) {
        $method = $request->method();
        if ($method === 'POST') {
            return Http::response(['simplified_invoice' => ['id' => 65]], 201);
        }
        if ($method === 'PUT') {
            $final = true;

            return Http::response(['simplified_invoice' => ['id' => 65]]);
        }
        $invoice = invoiceXpressIssueResponse($final ? 'final' : 'draft', [
            'type' => 'SimplifiedInvoice', 'sequence_id' => 22, 'atcud' => 'FSCD1234-7',
            'before_taxes' => 80, 'taxes' => 18.40, 'total' => 98.40,
        ])['invoice'];
        $invoice['items'][0] = array_replace($invoice['items'][0], [
            'unit_price' => 80, 'quantity' => 1, 'subtotal' => 80, 'tax_amount' => 18.40, 'total' => 98.40,
        ]);

        return Http::response(['simplified_invoice' => $invoice]);
    }, itemPrice: '80');
    $service = app(InvoiceXpressIssue::class);
    $preview = $service->preview($receivable->id, 42, 8, 1, documentType: 'simplified_invoice');
    expect($preview['sequence']['id'])->toBe(22);
    $attempt = $service->confirm($receivable->id, 42, 8, 1, $preview['revision'], documentType: 'simplified_invoice');

    expect($attempt->state)->toBe(InvoiceXpressIssueAttempt::Finalized)
        ->and($attempt->document_reference)->toBe('FS PILOTO/7');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->url(), '/simplified_invoices.json?')
        && $request['simplified_invoice']['sequence_id'] === '22');
});

it('rejects a taxable simplified service invoice above one hundred euros', function () {
    $receivable = invoiceXpressIssueReceivable();
    invoiceXpressIssueFake(fn () => Http::response([], 503));

    expect(fn () => app(InvoiceXpressIssue::class)->preview($receivable->id, 42, 8, 3, documentType: 'simplified_invoice'))
        ->toThrow(ValidationException::class, '100,00 €');
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
});

it('previews a domestic article 53 invoice with the expressly chosen M10 and the existing zero-rate catalogue', function () {
    $receivable = invoiceXpressIssueReceivable();
    $receivable->update(['amount' => 30015]);
    $receivable->billable->update(['vat_rate' => 0]);
    FiscalProfile::factory()->create(['vat_exempt' => true, 'country_code' => 'PT']);
    Http::preventStrayRequests();
    Http::fake([
        '*/clients/42.json?*' => Http::response(['client' => ['id' => 42, 'name' => 'Cliente Teste', 'country' => 'Portugal', 'fiscal_id' => '508025338']]),
        '*/items/8.json?*' => Http::response(['item' => ['id' => 8, 'name' => 'Consultoria', 'unit' => 'service', 'unit_price' => '100.0500',
            'tax' => ['id' => 9, 'name' => 'IVA Contiu', 'value' => 0]]]),
        '*/taxes.json?*' => Http::response(['taxes' => [['id' => 9, 'name' => 'IVA Contiu', 'region' => 'PT', 'code' => 'ISE', 'value' => 0]]]),
        '*/sequences/19.json?*' => Http::response(['sequence' => ['id' => 19, 'serie' => 'PILOTO',
            'current_invoice_sequence_id' => 19, 'current_invoice_validation_code' => 'ABCD1234']]),
    ]);
    $issue = app(InvoiceXpressIssue::class);

    expect(fn () => $issue->preview($receivable->id, 42, 8, 3))->toThrow(ValidationException::class);
    $preview = $issue->preview($receivable->id, 42, 8, 3, exemptionReason: 'M10');
    expect($preview['invoice']['tax_exemption_reason'])->toBe('M10')
        ->and($preview['totals'])->toBe(['net' => 30015, 'tax' => 0, 'total' => 30015]);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
});

function invoiceXpressIssueReceiptResponse(string $status): array
{
    return ['invoice_receipt' => invoiceXpressIssueResponse('draft', [
        'status' => $status, 'type' => 'InvoiceReceipt', 'sequence_id' => 25,
        'due_date' => '10/11/2026', 'inverted_sequence_number' => 'PILOTO/7', 'atcud' => 'FRCD1234-7',
    ])['invoice']];
}

it('only records the official invoice after a verified draft, finalization and final read-back', function () {
    $receivable = invoiceXpressIssueReceivable();
    $service = app(InvoiceXpressIssue::class);
    $final = false;
    invoiceXpressIssueFake(function (Request $request) use (&$final) {
        return match ($request->method()) {
            'POST' => Http::response(['invoice' => ['id' => 65, 'status' => 'draft']], 201),
            'PUT' => tap(Http::response(invoiceXpressIssueResponse('final')), function () use (&$final): void {
                $final = true;
                expect(InvoiceXpressIssueAttempt::sole()->provider_invoice_id)->toBeNull();
                expect(FiscalDocument::count())->toBe(0);
            }),
            'GET' => Http::response(invoiceXpressIssueResponse($final ? 'final' : 'draft')),
        };
    });

    $preview = $service->preview($receivable->id, 42, 8, 3);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    $result = $service->confirm($receivable->id, 42, 8, 3, $preview['revision']);

    expect($result->state)->toBe(InvoiceXpressIssueAttempt::Finalized)
        ->and($result->document_reference)->toBe('FT PILOTO/7')
        ->and($result->provider_invoice_id)->toBe(65)
        ->and($result->atcud)->toBe('ABCD1234-7')
        ->and(FiscalDocument::sole()->links->receivable_id)->toBe($receivable->id);
    expect(InvoiceXpressSnapshot::sole()->fiscal_document_id)->toBe($result->fiscal_document_id)
        ->and(app(InvoiceXpressImport::class)->reviewInvoice(invoiceXpressIssueResponse('final'))['state'])->toBe('same');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && parse_url($request->url(), PHP_URL_PATH) === '/invoices.json'
        && $request['proprietary_uid'] === $result->proprietary_uid
        && $request['invoice']['sequence_id'] === '19'
        && $request['invoice']['client'] === ['id' => 42]
        && $request['invoice']['items'] === [[
            'name' => 'Consultoria', 'description' => 'Serviço', 'unit' => 'service',
            'unit_price' => 100.05, 'quantity' => 3, 'tax' => ['name' => 'IVA Contiu'],
        ]]);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && parse_url($request->url(), PHP_URL_PATH) === '/invoices/65/change-state.json'
        && $request->data() === ['invoice' => ['state' => 'finalized']]);

    $retry = $service->confirm($receivable->id, 42, 8, 3, $preview['revision']);

    expect($retry->id)->toBe($result->id);
    expect(Http::recorded(fn (Request $request): bool => $request->method() !== 'GET'))->toHaveCount(2);
});

it('reuses the exact create payload after an uncertain create and recovers a completed PUT by GET', function (bool $providerBusy) {
    $receivable = invoiceXpressIssueReceivable();
    $service = app(InvoiceXpressIssue::class);
    $posts = [];
    $puts = 0;
    invoiceXpressIssueFake(function (Request $request) use (&$posts, &$puts, $providerBusy) {
        if ($request->method() === 'POST') {
            $posts[] = $request->data();

            if (count($posts) === 1) {
                if ($providerBusy) {
                    return Http::response(['errors' => [['error' => 'This document is being saved by another request. Please try again.']]], 422);
                }

                throw new ConnectionException('URL containing fake-test-credential');
            }

            return Http::response(['invoice' => ['id' => 65, 'status' => 'draft']], 201);
        }

        if ($request->method() === 'PUT') {
            $puts++;
            throw new ConnectionException('URL containing fake-test-credential');
        }

        return Http::response(invoiceXpressIssueResponse($puts === 0 ? 'draft' : 'final'));
    });

    $preview = $service->preview($receivable->id, 42, 8, 3);
    $first = $service->confirm($receivable->id, 42, 8, 3, $preview['revision']);
    expect($first->state)->toBe(InvoiceXpressIssueAttempt::Pending)
        ->and($first->provider_invoice_id)->toBeNull();

    $second = $service->recover($first->id);
    expect($second->state)->toBe(InvoiceXpressIssueAttempt::Draft)
        ->and($second->provider_invoice_id)->toBeNull()
        ->and($second->document_reference)->toBeNull();

    $result = $service->recover($first->id);

    expect($result->state)->toBe(InvoiceXpressIssueAttempt::Finalized)
        ->and($puts)->toBe(1)
        ->and($posts)->toHaveCount(2)
        ->and($posts[0])->toBe($posts[1]);
    $this->assertDatabaseCount('fiscal_documents', 1);
    $this->assertDatabaseCount('invoice_xpress_issue_attempts', 1);
})->with(['connection timeout' => false, 'provider busy with same key' => true]);

it('blocks a draft whose line VAT differs even if aggregate amounts match', function () {
    $receivable = invoiceXpressIssueReceivable();
    $service = app(InvoiceXpressIssue::class);
    invoiceXpressIssueFake(function (Request $request) {
        $response = invoiceXpressIssueResponse();
        $response['invoice']['items'][0]['tax']['name'] = 'IVA por defeito';

        return Http::response($response, $request->method() === 'POST' ? 201 : 200);
    });

    $preview = $service->preview($receivable->id, 42, 8, 3);
    $result = $service->confirm($receivable->id, 42, 8, 3, $preview['revision']);

    expect($result->state)->toBe(InvoiceXpressIssueAttempt::Blocked)
        ->and($result->provider_invoice_id)->toBeNull();
    $this->assertDatabaseEmpty('fiscal_documents');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
});

it('rejects stale confirmation parameters before a write', function () {
    $receivable = invoiceXpressIssueReceivable();
    $service = app(InvoiceXpressIssue::class);
    invoiceXpressIssueFake(fn () => Http::response([], 500));
    $preview = $service->preview($receivable->id, 42, 8, 3);
    $receivable->update(['due_on' => '2026-12-11']);

    expect(fn () => $service->confirm($receivable->id, 42, 8, 3, $preview['revision']))->toThrow(ValidationException::class);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    $this->assertDatabaseEmpty('invoice_xpress_issue_attempts');
});

it('requires the owner to confirm the exact visible preview on the independent page', function () {
    $receivable = invoiceXpressIssueReceivable();
    invoiceXpressIssueFake(fn () => Http::response([], 503));

    Livewire::test(InvoiceXpressIssuePage::class)
        ->set('receivableId', (string) $receivable->id)
        ->set('customerId', '42')
        ->set('itemId', '8')
        ->set('quantity', '3')
        ->call('preview')
        ->assertHasNoErrors()
        ->assertSee('Cliente Teste')
        ->assertSee('IVA Contiu')
        ->call('confirm')
        ->assertHasErrors(['accepted'])
        ->set('accepted', true)
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertSee('Por confirmar no InvoiceXpress');

    $this->assertDatabaseCount('invoice_xpress_issue_attempts', 1);
});

it('keeps the pilot page and writes unavailable without explicit selection or an active owner', function () {
    $receivable = invoiceXpressIssueReceivable();
    Http::preventStrayRequests();
    Http::fake();
    Setting::where('key', InvoiceXpressIssue::SettingsKey)->delete();

    expect(fn () => app(InvoiceXpressIssue::class)->preview($receivable->id, 42, 8, 3))
        ->toThrow(HttpException::class);
    Http::assertNothingSent();
});

it('refuses another owner or a changed provider account before any provider call', function () {
    $receivable = invoiceXpressIssueReceivable();
    $pilot = auth()->user();
    $other = User::factory()->create();
    $this->account->users()->attach($other, ['role' => 'owner']);
    $this->actingAs($other);
    Http::preventStrayRequests();
    Http::fake();

    expect(fn () => app(InvoiceXpressIssue::class)->preview($receivable->id, 42, 8, 3))
        ->toThrow(HttpException::class);

    $this->actingAs($pilot);
    InvoiceXpressConfig::store('different', 'other-fake-credential');

    expect(fn () => app(InvoiceXpressIssue::class)->preview($receivable->id, 42, 8, 3))
        ->toThrow(HttpException::class);
    Http::assertNothingSent();
});

it('lets an owner configure the pilot in the page without making provider calls', function () {
    invoiceXpressIssueReceivable();
    Setting::where('key', InvoiceXpressIssue::SettingsKey)->delete();
    Http::preventStrayRequests();
    Http::fake();

    Livewire::test(InvoiceXpressIssuePage::class)
        ->assertSee('Configuração da emissão')
        ->assertDontSee('Pré-visualizar fatura')
        ->set('approvedItems', ['8', '12'])
        ->set('taxName', 'IVA Contiu')
        ->set('sequenceId', '19')
        ->call('saveSettings')
        ->assertHasNoErrors()
        ->assertSee('Pré-visualizar fatura');

    expect(Setting::where('key', InvoiceXpressIssue::SettingsKey)->value('value'))->toBe([
        'provider' => 'invoicexpress', 'user_id' => auth()->id(), 'subdomain' => 'pilot',
        'item_ids' => [8, 12], 'tax_name' => 'IVA Contiu', 'sequence_id' => 19,
    ]);
    Http::assertNothingSent();
});

it('rejects settings changes while a remote attempt is unresolved and leaves it recoverable', function () {
    $receivable = invoiceXpressIssueReceivable();
    invoiceXpressIssueFake(fn () => Http::response([], 503));
    $service = app(InvoiceXpressIssue::class);
    $preview = $service->preview($receivable->id, 42, 8, 3);
    $attempt = $service->confirm($receivable->id, 42, 8, 3, $preview['revision']);

    Livewire::test(InvoiceXpressIssuePage::class)
        ->set('taxName', 'Outro IVA')
        ->call('saveSettings')
        ->assertHasErrors(['settings'])
        ->assertSee('Por confirmar no InvoiceXpress');

    expect(Setting::where('key', InvoiceXpressIssue::SettingsKey)->value('value')['tax_name'])->toBe('IVA Contiu')
        ->and(InvoiceXpressIssueAttempt::sole()->proprietary_uid)->toBe($attempt->proprietary_uid);
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'))->toHaveCount(1);

    Livewire::test(InvoiceXpressIssuePage::class)
        ->call('recover', $attempt->id)
        ->assertHasNoErrors()
        ->assertSee('Por confirmar no InvoiceXpress');

    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST')->map(fn ($pair) => $pair[0]['proprietary_uid'])->values()->all())
        ->toBe([$attempt->proprietary_uid, $attempt->proprietary_uid]);
    $this->assertDatabaseCount('invoice_xpress_issue_attempts', 1);
});

it('keeps a pending attempt visible after a connection switch and sends nothing to the new provider account', function () {
    $receivable = invoiceXpressIssueReceivable();
    invoiceXpressIssueFake(fn () => Http::response([], 503));
    $service = app(InvoiceXpressIssue::class);
    $preview = $service->preview($receivable->id, 42, 8, 3);
    $attempt = $service->confirm($receivable->id, 42, 8, 3, $preview['revision']);
    $previousCalls = Http::recorded()->count();
    InvoiceXpressConfig::store('changed', 'fake-rotated-credential');

    Livewire::test(InvoiceXpressIssuePage::class)
        ->assertSee('Por confirmar no InvoiceXpress')
        ->assertDontSee('Pré-visualizar fatura')
        ->call('recover', $attempt->id)
        ->assertHasErrors(['confirmation']);

    expect(Http::recorded())->toHaveCount($previousCalls)
        ->and($attempt->fresh()->state)->toBe(InvoiceXpressIssueAttempt::Pending);
});

it('forbids accountants from configuring issuance or opening its page', function () {
    invoiceXpressIssueReceivable();
    $accountant = User::factory()->create();
    $this->account->users()->attach($accountant, ['role' => 'accountant']);
    $this->actingAs($accountant);
    Http::preventStrayRequests();
    Http::fake();

    Livewire::test(InvoiceXpressIssuePage::class)->assertForbidden();
    expect(fn () => app(InvoiceXpressIssueSettings::class)->save('12', 'Outro IVA', '22'))
        ->toThrow(HttpException::class);

    expect(Setting::where('key', InvoiceXpressIssue::SettingsKey)->value('value')['item_ids'])->toBe([8]);
    Http::assertNothingSent();
});

it('previews each catalogue line and finalizes only an exact multi-line draft', function (bool $mismatch) {
    $receivable = invoiceXpressIssueReceivable();
    $settings = Setting::where('key', InvoiceXpressIssue::SettingsKey)->sole();
    $settings->update(['value' => array_replace($settings->value, ['item_ids' => [8, 12]])]);
    $final = false;
    invoiceXpressIssueFake(function (Request $request) use ($mismatch, &$final) {
        if ($request->method() === 'POST') {
            return Http::response(['invoice' => ['id' => 65]], 201);
        }

        if ($request->method() === 'PUT') {
            $final = true;
        }

        $response = invoiceXpressIssueResponse($final ? 'final' : 'draft');
        $first = array_replace($response['invoice']['items'][0], [
            'quantity' => 2, 'subtotal' => '200.10', 'tax_amount' => '46.02', 'total' => '246.12',
        ]);
        $response['invoice']['items'] = [$first, array_replace($first, [
            'name' => 'Suporte', 'description' => 'Apoio', 'unit' => 'hour', 'quantity' => 1,
            'subtotal' => '100.05', 'tax_amount' => $mismatch ? '23.02' : '23.01', 'total' => '123.06',
        ])];

        return Http::response($response);
    });

    Livewire::test(InvoiceXpressIssuePage::class)
        ->set('receivableId', (string) $receivable->id)
        ->set('customerId', '42')
        ->set('itemId', '8')
        ->set('quantity', '2')
        ->call('addLine')
        ->set('additionalLines.0.itemId', '12')
        ->call('preview')
        ->assertHasNoErrors()
        ->assertSee('Consultoria')
        ->assertSee('Suporte')
        ->assertSet('previewData.totals', ['net' => 30015, 'tax' => 6903, 'total' => 36918])
        ->set('additionalLines.0.quantity', '2')
        ->assertSet('previewData', null)
        ->set('additionalLines.0.quantity', '1')
        ->call('preview')
        ->set('accepted', true)
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertSee($mismatch ? 'Revisão manual necessária no InvoiceXpress.' : 'FT PILOTO/7');

    expect(InvoiceXpressIssueAttempt::sole()->state)->toBe($mismatch ? InvoiceXpressIssueAttempt::Blocked : InvoiceXpressIssueAttempt::Finalized);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && count($request['invoice']['items']) === 2
        && $request['invoice']['items'][1]['unit_price'] === 100.05);
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'PUT'))->toHaveCount($mismatch ? 0 : 1);
    $this->assertDatabaseCount('fiscal_documents', $mismatch ? 0 : 1);
})->with(['exact lines' => false, 'different line tax with the same document totals' => true]);

it('settles a fatura-recibo and links the existing payment exactly once after uncertain finalization', function () {
    $receivable = invoiceXpressIssueReceivable();
    $payment = Payment::factory()->for($receivable)->create(['amount' => $receivable->amount]);
    $settled = false;
    $finalizations = [];
    invoiceXpressIssueFake(function (Request $request) use (&$settled, &$finalizations) {
        return match ($request->method()) {
            'POST' => Http::response(['invoice_receipt' => ['id' => 65]], 201),
            'PUT' => (function () use ($request, &$settled, &$finalizations) {
                $finalizations[] = [parse_url($request->url(), PHP_URL_PATH), $request->data()];
                $settled = true;
                throw new ConnectionException('Fake timeout');
            })(),
            'GET' => Http::response(invoiceXpressIssueReceiptResponse($settled ? 'settled' : 'draft')),
        };
    });

    $page = Livewire::test(InvoiceXpressIssuePage::class)
        ->set('documentType', 'invoice_receipt')
        ->set('receivableId', (string) $receivable->id)
        ->set('customerId', '42')
        ->set('itemId', '8')
        ->set('quantity', '3')
        ->call('preview')
        ->assertHasNoErrors()
        ->assertSet('previewData.payment.id', $payment->id)
        ->assertSee('Fatura-recibo');

    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    $page->set('accepted', true)->call('confirm')->assertHasNoErrors();

    $attempt = InvoiceXpressIssueAttempt::sole();
    expect($attempt->state)->toBe(InvoiceXpressIssueAttempt::Draft);
    $this->assertDatabaseEmpty('fiscal_documents');

    // Read-back and local reconciliation remain available after provider writes are disabled.
    config(['services.invoicexpress.catalog_safe_accounts' => []]);
    $page->call('recover', $attempt->id)->assertHasNoErrors()->assertSee('FR PILOTO/7');
    $page->call('recover', $attempt->id)->assertHasNoErrors();

    $document = FiscalDocument::sole();
    expect($document->type->value)->toBe('invoice_receipt')
        ->and($document->links->payment_id)->toBe($payment->id)
        ->and($document->links->receivable_id)->toBe($receivable->id)
        ->and($receivable->fresh()->outstanding)->toBe(0)
        ->and($attempt->fresh()->state)->toBe(InvoiceXpressIssueAttempt::Finalized);
    $this->assertDatabaseCount('payments', 1);
    $this->assertDatabaseCount('payment_allocations', 1);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && parse_url($request->url(), PHP_URL_PATH) === '/invoice_receipts.json'
        && $request['invoice_receipt']['sequence_id'] === '25');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && parse_url($request->url(), PHP_URL_PATH) === '/invoice_receipts/65.json');
    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'))->toHaveCount(1);
    expect($finalizations)->toBe([['/invoice_receipts/65/change-state.json', ['invoice' => ['state' => 'finalized']]]]);
});

it('rejects an ineligible receipt payment before contacting the provider', function (array $changes) {
    $receivable = invoiceXpressIssueReceivable();
    Payment::factory()->for($receivable)->create($changes + ['amount' => $receivable->amount]);
    invoiceXpressIssueFake(fn () => Http::response([], 503));

    Livewire::test(InvoiceXpressIssuePage::class)
        ->set('documentType', 'invoice_receipt')
        ->set('receivableId', (string) $receivable->id)
        ->set('customerId', '42')
        ->set('itemId', '8')
        ->set('quantity', '3')
        ->call('preview')
        ->assertHasErrors(['payment']);

    Http::assertNothingSent();
    $this->assertDatabaseEmpty('invoice_xpress_issue_attempts');
})->with([
    'partial payment' => [['amount' => 36917]],
    'older payment' => [['paid_on' => '2026-11-09']],
    'previous invoice reference' => [['invoice_number' => 'FT PREV/1']],
]);

it('keeps receipt recovery from finalizing a draft when payment or catalogue approval changes', function (bool $paymentChanged) {
    $receivable = invoiceXpressIssueReceivable();
    $payment = Payment::factory()->for($receivable)->create(['amount' => $receivable->amount]);
    $unavailable = true;
    invoiceXpressIssueFake(function (Request $request) use (&$unavailable) {
        return match ($request->method()) {
            'POST' => Http::response(['invoice_receipt' => ['id' => 65]], 201),
            'GET' => $unavailable ? Http::response([], 503) : Http::response(invoiceXpressIssueReceiptResponse('draft')),
            default => throw new LogicException('Recovery must leave the provider draft untouched.'),
        };
    });

    $service = app(InvoiceXpressIssue::class);
    $preview = $service->preview($receivable->id, 42, 8, 3, documentType: 'invoice_receipt');
    $attempt = $service->confirm($receivable->id, 42, 8, 3, $preview['revision'], documentType: 'invoice_receipt');
    expect($attempt->state)->toBe(InvoiceXpressIssueAttempt::Draft);

    $unavailable = false;
    if ($paymentChanged) {
        $payment->update(['amount' => $payment->amount - 1]);
        expect($service->recover($attempt->id)->state)->toBe(InvoiceXpressIssueAttempt::Blocked);
    } else {
        config(['services.invoicexpress.catalog_safe_accounts' => []]);
        expect(fn () => $service->recover($attempt->id))->toThrow(ValidationException::class)
            ->and($attempt->fresh()->state)->toBe(InvoiceXpressIssueAttempt::Draft);
    }

    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'))->toHaveCount(1);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    $this->assertDatabaseEmpty('fiscal_documents');
    $this->assertDatabaseCount('payments', 1);
})->with(['payment changes' => true, 'catalogue approval removed' => false]);

function invoiceXpressAllocatedAdvance(Receivable $receivable, int $net = 10005, int $vat = 2301, bool $documented = true): ?FiscalDocument
{
    $advances = app(ManageCustomerAdvance::class);
    $payment = $advances->record($receivable->client->id, $net + $vat, '2026-11-01', PaymentMethod::Transfer);
    $advances->apply($payment->id, $receivable->id, '2026-11-05');

    if (! $documented) {
        return null;
    }

    $advance = FiscalDocument::factory()->create([
        'type' => FiscalDocumentType::InvoiceReceipt, 'number' => 'FR SINAL/1', 'document_date' => '2026-11-01',
        'net_amount' => $net, 'vat_amount' => $vat, 'total_amount' => $net + $vat,
        'party_tax_id' => '508025338',
    ]);
    $advance->links()->create(['payment_id' => $payment->id]);

    return $advance;
}

it('previews the full service, explicit advance deduction and residual VAT without preparing a provider write', function (int $advanceNet, int $advanceVat, array $balance) {
    $receivable = invoiceXpressIssueReceivable();
    $advance = invoiceXpressAllocatedAdvance($receivable, $advanceNet, $advanceVat);
    config(['services.invoicexpress.catalog_safe_accounts' => []]);
    invoiceXpressIssueFake(fn () => throw new LogicException('Unexpected remote document call.'));

    $page = Livewire::test(InvoiceXpressIssuePage::class)
        ->set('receivableId', (string) $receivable->id)
        ->set('customerId', '42')
        ->set('itemId', '8')
        ->set('quantity', '3')
        ->call('preview')
        ->assertHasNoErrors()
        ->assertSee('FR SINAL/1')
        ->assertSee('Ligar adiantamento')
        ->assertDontSee('Confirmar e emitir')
        ->assertSet('previewData.totals', ['net' => 30015, 'tax' => 6903, 'total' => 36918])
        ->assertSet('previewData.advanceSettlement.balance', $balance)
        ->assertSet('previewData.advanceSettlement.deduction.total', $advance->total_amount);

    expect($page->get('previewData'))->not->toHaveKey('invoice')->not->toHaveKey('revision');
    $page->set('accepted', true)->call('confirm')->assertHasErrors(['confirmation']);
    Http::assertSentCount(3);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    $this->assertDatabaseEmpty('invoice_xpress_issue_attempts');
    $this->assertDatabaseCount('fiscal_documents', 1);
    $this->assertDatabaseCount('payments', 1);
    $this->assertDatabaseCount('payment_allocations', 1);
})->with([
    'partial advance' => [10005, 2301, ['net' => 20010, 'tax' => 4602, 'total' => 24612]],
    'full advance' => [30015, 6903, ['net' => 0, 'tax' => 0, 'total' => 0]],
]);

it('blocks direct confirmation and recovery of a pending gross invoice after an advance is allocated even on an allowlisted account', function () {
    $receivable = invoiceXpressIssueReceivable();
    invoiceXpressIssueFake(fn () => Http::response([], 503));
    $issue = app(InvoiceXpressIssue::class);
    $preview = $issue->preview($receivable->id, 42, 8, 3);
    $attempt = $issue->confirm($receivable->id, 42, 8, 3, $preview['revision']);
    invoiceXpressAllocatedAdvance($receivable, documented: false);
    $calls = Http::recorded()->count();

    expect(fn () => $issue->confirm($receivable->id, 42, 8, 3, $preview['revision']))
        ->toThrow(ValidationException::class, 'A emissão do acerto pela API está bloqueada');
    expect($issue->recover($attempt->id)->state)->toBe(InvoiceXpressIssueAttempt::Blocked)
        ->and(Http::recorded())->toHaveCount($calls);
    $this->assertDatabaseCount('invoice_xpress_issue_attempts', 1);
    $this->assertDatabaseEmpty('fiscal_documents');
});

it('requires an unambiguous original invoice at the service VAT rate before preparing the adjustment', function (int $net, int $vat, bool $documented) {
    $receivable = invoiceXpressIssueReceivable();
    invoiceXpressAllocatedAdvance($receivable, $net, $vat, $documented);
    Http::preventStrayRequests();

    try {
        app(InvoiceXpressIssue::class)->preview($receivable->id, 42, 8, 3);
        test()->fail('The advance was not rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('advanceSettlement');
    }

    Http::assertNothingSent();
})->with([
    'missing fiscal document' => [10005, 2301, false],
    'different VAT rate' => [10005, 1301, true],
]);

it('recovers an already finalized remote invoice by reading it and refuses a second invoice for its charge', function () {
    $receivable = invoiceXpressIssueReceivable();
    $finalized = false;
    invoiceXpressIssueFake(function (Request $request) use (&$finalized) {
        if ($request->method() === 'POST') {
            return Http::response(['invoice' => ['id' => 65]], 201);
        }

        if ($request->method() === 'PUT') {
            $finalized = true;

            throw new ConnectionException('Fake finalization timeout');
        }

        return Http::response(invoiceXpressIssueResponse($finalized ? 'final' : 'draft'));
    });

    $issue = app(InvoiceXpressIssue::class);
    $preview = $issue->preview($receivable->id, 42, 8, 3);
    $attempt = $issue->confirm($receivable->id, 42, 8, 3, $preview['revision']);
    expect($attempt->state)->toBe(InvoiceXpressIssueAttempt::Draft);

    invoiceXpressAllocatedAdvance($receivable);
    expect(fn () => $issue->preview($receivable->id, 42, 8, 3))
        ->toThrow(ValidationException::class, 'Verifica a tentativa de emissão existente');

    config(['services.invoicexpress.catalog_safe_accounts' => []]);
    $before = Http::recorded()->count();
    expect($issue->recover($attempt->id)->state)->toBe(InvoiceXpressIssueAttempt::Finalized);
    expect(Http::recorded()->slice($before)->map(fn ($pair) => $pair[0]->method())->values()->all())->toBe(['GET']);
    expect(fn () => $issue->preview($receivable->id, 42, 8, 3))
        ->toThrow(ValidationException::class, 'Já existe um documento de faturação ativo');
    $this->assertDatabaseCount('fiscal_documents', 2);
});
