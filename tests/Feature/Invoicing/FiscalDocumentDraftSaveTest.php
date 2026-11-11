<?php

use App\Actions\SaveFiscalDocumentDraft;
use App\Enums\FiscalDocumentStatus;
use App\Enums\IntegrationOperationStatus;
use App\Enums\IntegrationRecordState;
use App\Models\Account;
use App\Models\Article;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Models\IntegrationConnection;
use App\Models\IntegrationOperation;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;
use App\Support\Invoicing\Invoicing;
use App\Support\Invoicing\InvoicingRecords;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

pest()->group('invoicing', 'fiscal');

/*
 * Saving a document that is still being written. The app keeps the header and the lines; the
 * draft is created at the provider straight away so it can be checked there before issuing.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-12 10:00'));
    $this->actingAs(ownerOf($this->account));
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->account);
    invoicingConnection();
    $this->client = Client::factory()->create(['name' => 'Estúdio Atlântico, Lda.', 'vat_number' => '508025338']);
    $this->invoice = fn (array $overrides = []): array => [
        'type' => 'invoice',
        'client_id' => $this->client->id,
        'document_date' => '2026-11-12',
        'due_date' => '2026-12-12',
        'vat_exemption_reason' => 'M10',
        'lines' => [
            ['description' => 'Desenho do site institucional', 'quantity' => 1000, 'unit' => 'un', 'unit_price' => 15000],
            ['description' => 'Hora de desenvolvimento', 'quantity' => 2000, 'unit' => 'h', 'unit_price' => 4500],
            ['description' => 'Alojamento anual', 'quantity' => 1000, 'unit' => 'un', 'unit_price' => 6000, 'discount_basis_points' => 1000],
        ],
        ...$overrides,
    ];
    $this->provider = fn (array $routes = []) => fakeInvoiceXpress([
        'POST /clients.json' => ['client' => ['id' => 77]],
        'POST /invoices.json' => invoiceXpressDocument(['before_taxes' => 294.0, 'total' => 294.0]),
        ...$routes,
    ]);
});

it('keeps the header, the lines and their totals, and creates the draft at the provider', function () {
    ($this->provider)();

    $document = app(SaveFiscalDocumentDraft::class)->handle(($this->invoice)(['withholding_basis_points' => 2300, 'client_reference' => 'EA-2026-118']));

    expect($document->status)->toBe(FiscalDocumentStatus::Draft)
        ->and($document->number)->toBeNull()
        ->and($document->origin)->toBe('InvoiceXpress')
        ->and($document->party_name)->toBe('Estúdio Atlântico, Lda.')
        ->and([$document->net_amount, $document->discount_amount, $document->total_amount, $document->withholding_amount])->toBe([29400, 600, 29400, 6762])
        ->and($document->payableAmount())->toBe(22638)
        ->and($document->lines)->toHaveCount(3)
        ->and($document->lines[2]->net_amount)->toBe(5400);

    $record = InvoicingRecords::for($document);
    expect($record->remote_id)->toBe('9001')
        ->and($record->remote_type)->toBe('invoices')
        ->and($record->state)->toBe(IntegrationRecordState::InSync)
        ->and(IntegrationOperation::query()->where('kind', 'draft_saved')->value('status'))->toBe(IntegrationOperationStatus::Done);
});

it('creates the client at the provider once and reuses it afterwards', function () {
    ($this->provider)();

    app(SaveFiscalDocumentDraft::class)->handle(($this->invoice)());
    app(SaveFiscalDocumentDraft::class)->handle(($this->invoice)());

    // The series and the taxes once, the client once and the two drafts: nothing else.
    Http::assertSentCount(5);
    expect(Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/clients.json')))->toHaveCount(1)
        ->and(InvoicingRecords::remoteId($this->client))->toBe('77');
});

it('creates an article at the provider the first time it is billed', function () {
    ($this->provider)(['POST /items.json' => ['item' => ['id' => 501]]]);
    $article = Article::factory()->create(['name' => 'Hora de desenvolvimento', 'unit' => 'h', 'unit_price' => 4500]);
    $line = ['article_id' => $article->id, 'description' => 'Hora de desenvolvimento · formulário', 'quantity' => 2000, 'unit' => 'h', 'unit_price' => 4500];

    $action = app(SaveFiscalDocumentDraft::class);
    $action->handle(($this->invoice)(['lines' => [$line]]));
    expect($action->sendError)->toBeNull();
    app(SaveFiscalDocumentDraft::class)->handle(($this->invoice)(['lines' => [$line]]));

    expect(InvoicingRecords::remoteId($article))->toBe('501')
        ->and(Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/items.json')))->toHaveCount(1);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/invoices.json')
        && $request->data()['invoice']['items'][0]['name'] === 'Hora de desenvolvimento'
        && $request->data()['invoice']['items'][0]['description'] === 'Hora de desenvolvimento · formulário');
});

it('updates the same draft at the provider when it is saved again', function () {
    ($this->provider)([
        'PUT /invoices/9001.json' => [],
        'GET /invoices/9001.json' => invoiceXpressDocument(['before_taxes' => 150.0, 'total' => 150.0]),
    ]);
    $draft = app(SaveFiscalDocumentDraft::class)->handle(($this->invoice)());

    $saved = app(SaveFiscalDocumentDraft::class)->handle(($this->invoice)(['lines' => [
        ['description' => 'Só o desenho', 'quantity' => 1000, 'unit' => 'un', 'unit_price' => 15000],
    ]]), $draft);

    expect($saved->id)->toBe($draft->id)
        ->and($saved->lines()->pluck('description')->all())->toBe(['Só o desenho'])
        ->and($saved->total_amount)->toBe(15000)
        ->and(FiscalDocument::query()->count())->toBe(1)
        ->and(Http::recorded(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), '/invoices.json')))->toHaveCount(1);
});

it('keeps the draft in the app when the provider refuses it, and says why', function () {
    ($this->provider)(['POST /invoices.json' => fn () => Http::response(['errors' => [['error' => 'O cliente não tem NIF']]], 422)]);

    $action = app(SaveFiscalDocumentDraft::class);
    $document = $action->handle(($this->invoice)());

    expect($action->sendError)->toContain('O cliente não tem NIF')->and(FiscalDocument::query()->count())->toBe(1);

    $operation = IntegrationOperation::query()->where('kind', 'draft_saved')->sole();

    expect($document->status)->toBe(FiscalDocumentStatus::Draft)
        ->and(InvoicingRecords::for($document))->toBeNull()
        ->and($operation->status)->toBe(IntegrationOperationStatus::Failed)
        ->and($operation->detail)->toContain('O cliente não tem NIF');
});

it('flags the draft when the provider adds it up differently', function () {
    ($this->provider)(['POST /invoices.json' => invoiceXpressDocument(['before_taxes' => 300.0, 'total' => 300.0])]);

    $document = app(SaveFiscalDocumentDraft::class)->handle(($this->invoice)());

    expect(InvoicingRecords::for($document)->state)->toBe(IntegrationRecordState::Differs)
        ->and($document->fresh()->total_amount)->toBe(29400);
});

it('takes the provider\'s VAT when it only differs by rounding', function () {
    app(FinanceSettings::class)->set(['vat_region' => 'PT']);
    ($this->provider)(['POST /invoices.json' => invoiceXpressDocument(['before_taxes' => 0.99, 'taxes' => 0.24, 'total' => 1.23])]);

    $document = app(SaveFiscalDocumentDraft::class)->handle(($this->invoice)([
        'vat_exemption_reason' => null,
        'lines' => array_fill(0, 3, ['description' => 'Linha', 'quantity' => 1000, 'unit' => 'un', 'unit_price' => 33, 'vat_basis_points' => 2300]),
    ]));

    expect($document->fresh()->vat_amount)->toBe(24)
        ->and($document->fresh()->total_amount)->toBe(123)
        ->and(InvoicingRecords::for($document)->state)->toBe(IntegrationRecordState::InSync);
});

it('refuses a document that breaks its own rules', function (array $overrides, string $field) {
    ($this->provider)();

    try {
        app(SaveFiscalDocumentDraft::class)->handle(($this->invoice)($overrides));
        $this->fail('The document was saved.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }

    expect(FiscalDocument::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'no lines' => [['lines' => []], 'lines'],
    'no client' => [['client_id' => null], 'client_id'],
    'due before the date' => [['due_date' => '2026-11-01'], 'due_date'],
    'no VAT and no reason for it' => [['vat_exemption_reason' => null], 'vat_exemption_reason'],
    'a VAT rate the region does not have' => [['lines' => [['description' => 'x', 'quantity' => 1000, 'unit' => 'un', 'unit_price' => 100, 'vat_basis_points' => 1700]]], 'lines.0.vat_basis_points'],
    'withholding under 25 euros' => [['withholding_basis_points' => 2300, 'lines' => [['description' => 'x', 'quantity' => 1000, 'unit' => 'un', 'unit_price' => 5000]]], 'withholding_basis_points'],
    'a simplified invoice above its limit' => [['type' => 'simplified_invoice', 'payment' => ['method' => 'cash', 'paid_on' => '2026-11-12']], 'lines'],
    'a fatura-recibo without the payment' => [['type' => 'invoice_receipt'], 'payment'],
    'a transport guide without the transport' => [['type' => 'transport_guide'], 'transport'],
    'a credit note without its invoice' => [['type' => 'credit_note', 'reason' => 'Erro'], 'original_id'],
]);

it('never accepts a client, an article or an invoice of another account', function () {
    ($this->provider)();
    $foreign = CurrentAccount::run(Account::factory()->create(), fn () => Client::factory()->create());

    expect(fn () => app(SaveFiscalDocumentDraft::class)->handle(($this->invoice)(['client_id' => $foreign->id])))
        ->toThrow(ValidationException::class);
});

it('does not let an issued document be saved as a draft again', function () {
    ($this->provider)();
    $issued = FiscalDocument::factory()->create();

    expect(fn () => app(SaveFiscalDocumentDraft::class)->handle(($this->invoice)(), $issued))->toThrow(ValidationException::class);
    expect($issued->fresh()->status)->toBe(FiscalDocumentStatus::Active);
});

it('needs a provider connected', function () {
    IntegrationConnection::query()->delete();
    Invoicing::forget();

    expect(fn () => app(SaveFiscalDocumentDraft::class)->handle(($this->invoice)()))->toThrow(ValidationException::class);
});
