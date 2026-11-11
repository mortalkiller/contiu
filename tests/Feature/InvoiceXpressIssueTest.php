<?php

use App\Enums\FiscalDocumentType;
use App\Filament\Pages\InvoiceXpressIssuePage;
use App\Models\FiscalDocument;
use App\Models\InvoiceXpressConfig;
use App\Models\InvoiceXpressIssueAttempt;
use App\Models\InvoiceXpressSnapshot;
use App\Models\Setting;
use App\Models\User;
use App\Support\InvoiceXpressImport;
use App\Support\InvoiceXpressIssue;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

pest()->group('invoicexpress');

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
})->group('integration');

it('previews confirmed withholding on the base in cents and requires separate validation before writing', function () {
    $charge = invoiceXpressIssueReceivable();
    invoiceXpressIssueFake(fn () => Http::response([], 503));
    $issue = app(InvoiceXpressIssue::class);
    $preview = $issue->preview($charge->id, 42, 8, 3, withholdingBasisPoints: 2300);

    expect($preview['withholding'])->toBe(['basisPoints' => 2300, 'gross' => 36918, 'retained' => 6903, 'cash' => 30015])
        ->and($preview['invoice']['retention_basis_points'])->toBe(2300)
        ->and($charge->fresh()->outstanding)->toBe(36918);
    expect(fn () => $issue->confirm($charge->id, 42, 8, 3, $preview['revision'], withholdingBasisPoints: 2300))
        ->toThrow(ValidationException::class, 'validação da retenção');

    $this->assertDatabaseEmpty('withholding_records');
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
})->group('integration');

it('prepares a direct invoice with a discount in integer cents and keeps writes blocked', function () {
    $existing = invoiceXpressIssueReceivable();
    config(['services.invoicexpress.catalog_safe_accounts' => []]);
    invoiceXpressIssueFake(fn () => Http::response([], 503));
    $service = app(InvoiceXpressIssue::class);
    $direct = ['key' => '8c832a1a-fbe1-4d6a-a036-36263d199183', 'clientId' => $existing->client->id, 'dueOn' => '2026-12-10'];
    $preview = $service->preview(0, 42, 8, 3, discountBasisPoints: 1000, direct: $direct);

    expect($preview['totals'])->toMatchArray(['discount' => 3002, 'net' => 27013, 'tax' => 6213, 'total' => 33226])
        ->and($preview['invoice']['items'][0])->toMatchArray(['unit_price_cents' => 10005, 'discount_basis_points' => 1000])
        ->and($preview['invoice']['items'][0])->not->toHaveKey('unit_price');
    expect(fn () => $service->confirm(0, 42, 8, 3, $preview['revision'], discountBasisPoints: 1000, direct: $direct))
        ->toThrow(ValidationException::class, 'preserva o catálogo');

    $this->assertDatabaseCount('receivables', 1);
    $this->assertDatabaseCount('invoice_xpress_issue_attempts', 0);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
})->group('integration');

it('refuses a new invoice for a charge already documented by a debit note', function () {
    $receivable = invoiceXpressIssueReceivable();
    FiscalDocument::factory()->create(['type' => FiscalDocumentType::DebitNote])
        ->links()->create(['receivable_id' => $receivable->id]);
    invoiceXpressIssueFake(fn () => Http::response([], 503));

    expect(fn () => app(InvoiceXpressIssue::class)->preview($receivable->id, 42, 8, 3))
        ->toThrow(ValidationException::class);
    Http::assertNothingSent();
})->group('integration');

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
})->group('integration');

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
})->group('integration');

it('rejects stale confirmation parameters before a write', function () {
    $receivable = invoiceXpressIssueReceivable();
    $service = app(InvoiceXpressIssue::class);
    invoiceXpressIssueFake(fn () => Http::response([], 500));
    $preview = $service->preview($receivable->id, 42, 8, 3);
    $receivable->update(['due_on' => '2026-12-11']);

    expect(fn () => $service->confirm($receivable->id, 42, 8, 3, $preview['revision']))->toThrow(ValidationException::class);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    $this->assertDatabaseEmpty('invoice_xpress_issue_attempts');
})->group('integration');

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
})->group('ui');

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
})->group('integration');

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
})->with(['exact lines' => false, 'different line tax with the same document totals' => true])->group('ui');

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
])->group('ui');

describe('Issuance progress', function () {
    beforeEach(function () {
        Http::preventStrayRequests();
        $this->travelTo(now()->setDate(2026, 11, 10));
    });

    it('shows the three steps of issuing an invoice with nothing done yet', function () {
        $owner = actingAsAdmin();
        config(['services.invoicexpress.catalog_safe_accounts' => ['pilot']]);
        InvoiceXpressConfig::store('pilot', 'fake-test-credential');
        Setting::create(['key' => 'invoice_xpress_issue', 'value' => [
            'provider' => 'invoicexpress', 'user_id' => $owner->id, 'subdomain' => 'pilot',
            'item_ids' => [8], 'tax_name' => 'IVA Contiu', 'sequence_id' => 19,
        ]]);

        Livewire::test(InvoiceXpressIssuePage::class)
            ->assertSee('A fatura é emitida no InvoiceXpress e fica ligada à cobrança.')
            ->assertSeeInOrder(['1. Dados do documento', 'Por preencher', '2. Confirmar emissão', 'Depois da pré-visualização', '3. Emitida', 'Ainda sem tentativas'])
            ->assertDontSee('só se corrige com nota de crédito');

        Http::assertNothingSent();
    })->group('ui');
});
