<?php

use App\Actions\IssueFiscalDocument;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Enums\IntegrationRecordState;
use App\Exceptions\InvoicingProviderException;
use App\Models\Client;
use App\Models\CreditApplication;
use App\Models\DirectInvoiceSale;
use App\Models\FiscalDocument;
use App\Models\IntegrationOperation;
use App\Models\OneOffIncome;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\User;
use App\Support\AccountantAccess;
use App\Support\Invoicing\Invoicing;
use App\Support\Invoicing\InvoicingRecords;
use App\Support\Invoicing\RemoteDocument;
use App\Support\Invoicing\RemoteDocumentState;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

pest()->group('invoicing', 'fiscal');

/*
 * Issuing a draft: the provider issues, the app keeps the number and the ATCUD, and what the
 * document means for receivables and payments happens with it. Issuing twice issues once.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-12 10:00'));
    $this->actingAs(ownerOf($this->account));
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->account);
    $this->connection = invoicingConnection();

    /** A draft the provider already holds as document 9001. */
    $this->draftAtProvider = function (array $attributes = [], ?array $lines = null): FiscalDocument {
        $document = $lines === null ? draftDocument($attributes) : draftDocument($attributes, $lines);
        InvoicingRecords::store($document, new RemoteDocument('invoices', '9001', $document->type, RemoteDocumentState::Draft, CarbonImmutable::parse('2026-11-12'), $document->net_amount, $document->vat_amount, $document->total_amount), IntegrationRecordState::InSync);

        return $document;
    };

    /** InvoiceXpress holding the draft, then the issued document once it is finalised. */
    $this->provider = function (array $draft = [], array $routes = []): void {
        $issued = false;
        fakeInvoiceXpress([
            'GET /invoices/9001.json' => function () use (&$issued, $draft) {
                return Http::response(invoiceXpressDocument($issued
                    ? [...$draft, 'status' => 'final', 'inverted_sequence_number' => '2026/15', 'atcud' => 'JFX4K2Q9-15']
                    : $draft));
            },
            'PUT /invoices/9001/change-state.json' => function () use (&$issued) {
                $issued = true;

                return Http::response('', 200);
            },
            ...$routes,
        ]);
    };

    $this->finalised = fn (): int => Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'change-state'))->count();
});

it('issues the draft and keeps the number, the series and the ATCUD the provider gives back', function () {
    ($this->provider)();
    $draft = ($this->draftAtProvider)();

    $document = app(IssueFiscalDocument::class)->handle($draft);

    expect($document->status)->toBe(FiscalDocumentStatus::Active)
        ->and($document->number)->toBe('FT 2026/15')
        ->and($document->series)->toBe('2026')
        ->and($document->atcud)->toBe('JFX4K2Q9-15')
        ->and($document->issued_at)->not->toBeNull()
        ->and(InvoicingRecords::for($document)->state)->toBe(IntegrationRecordState::InSync)
        ->and(IntegrationOperation::query()->where('kind', 'issued')->value('summary'))->toContain('FT 2026/15');
});

it('issues only once when asked twice', function () {
    ($this->provider)();
    $draft = ($this->draftAtProvider)();

    app(IssueFiscalDocument::class)->handle($draft);
    $again = app(IssueFiscalDocument::class)->handle($draft);

    expect($again->number)->toBe('FT 2026/15')->and(($this->finalised)())->toBe(1);
});

it('refuses a second request while the first is still issuing', function () {
    ($this->provider)();
    $draft = ($this->draftAtProvider)();
    Cache::lock("invoicing:issue:{$draft->id}", 60)->get();

    expect(fn () => app(IssueFiscalDocument::class)->handle($draft))->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

it('picks up a document the provider issued before the answer was recorded', function () {
    $draft = ($this->draftAtProvider)();
    fakeInvoiceXpress(['GET /invoices/9001.json' => invoiceXpressDocument(['status' => 'final', 'inverted_sequence_number' => '2026/15', 'atcud' => 'JFX4K2Q9-15'])]);

    $document = app(IssueFiscalDocument::class)->handle($draft);

    expect($document->number)->toBe('FT 2026/15')->and(($this->finalised)())->toBe(0);
});

it('stops, without a number, when the provider adds the document up differently', function () {
    ($this->provider)(['before_taxes' => 180.0, 'total' => 180.0], ['PUT /invoices/9001.json' => [], 'POST /clients.json' => ['client' => ['id' => 77]]]);
    $draft = ($this->draftAtProvider)();

    expect(fn () => app(IssueFiscalDocument::class)->handle($draft))->toThrow(ValidationException::class);

    expect($draft->fresh()->status)->toBe(FiscalDocumentStatus::Draft)
        ->and($draft->fresh()->number)->toBeNull()
        ->and(InvoicingRecords::for($draft)->state)->toBe(IntegrationRecordState::Differs)
        ->and(($this->finalised)())->toBe(0);
});

it('keeps the draft and the reason when the provider refuses to issue', function () {
    ($this->provider)([], ['PUT /invoices/9001/change-state.json' => fn () => Http::response(['errors' => [['error' => 'Série não comunicada à AT']]], 422)]);
    $draft = ($this->draftAtProvider)();

    expect(fn () => app(IssueFiscalDocument::class)->handle($draft))->toThrow(InvoicingProviderException::class, 'Série não comunicada à AT');

    expect($draft->fresh()->status)->toBe(FiscalDocumentStatus::Draft)
        ->and(IntegrationOperation::query()->where('kind', 'issued')->value('detail'))->toContain('Série não comunicada à AT');
});

it('creates what the client owes when an invoice is issued with nothing to collect behind it', function () {
    ($this->provider)();
    $draft = ($this->draftAtProvider)(['due_date' => '2026-12-12']);

    $document = app(IssueFiscalDocument::class)->handle($draft);
    $receivable = $document->links->receivable;

    expect($receivable->amount)->toBe(15000)
        ->and($receivable->due_on->toDateString())->toBe('2026-12-12')
        ->and($receivable->client->id)->toBe($document->client_id)
        ->and(DirectInvoiceSale::query()->where('fiscal_document_id', $document->id)->exists())->toBeTrue();
});

it('leaves the receivable the invoice was written for as it is', function () {
    ($this->provider)();
    $draft = ($this->draftAtProvider)();
    $receivable = Receivable::factory()->for(Project::factory()->for($draft->client), 'billable')->create(['amount' => 15000]);
    $draft->links()->create(['receivable_id' => $receivable->id]);

    $document = app(IssueFiscalDocument::class)->handle($draft);

    expect($document->links->receivable_id)->toBe($receivable->id)
        ->and(DirectInvoiceSale::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0);
});

it('records the payment a fatura-recibo says came in', function () {
    fakeInvoiceXpress([
        'GET /invoice_receipts/9001.json' => invoiceXpressDocument(['type' => 'InvoiceReceipt', 'status' => 'settled', 'inverted_sequence_number' => '2026/15'], 'invoice_receipt'),
    ]);
    $draft = draftDocument(['type' => FiscalDocumentType::InvoiceReceipt, 'payment' => ['method' => 'mbway', 'paid_on' => '2026-11-12']]);
    InvoicingRecords::store($draft, new RemoteDocument('invoice_receipts', '9001', $draft->type, RemoteDocumentState::Draft, CarbonImmutable::parse('2026-11-12'), 15000, 0, 15000), IntegrationRecordState::InSync);
    $receivable = Receivable::factory()->for(Project::factory()->for($draft->client), 'billable')->create(['amount' => 15000]);
    $draft->links()->create(['receivable_id' => $receivable->id]);

    $document = app(IssueFiscalDocument::class)->handle($draft);
    $payment = Payment::query()->sole();

    expect($document->number)->toBe('FR 2026/15')
        ->and($payment->amount)->toBe(15000)
        ->and($payment->method->value)->toBe('mbway')
        ->and($payment->paid_on->toDateString())->toBe('2026-11-12')
        ->and($document->links->payment_id)->toBe($payment->id)
        ->and($receivable->fresh()->outstanding)->toBe(0);
});

it('records the sale and its payment together when a simplified invoice has no receivable', function () {
    fakeInvoiceXpress([
        'GET /simplified_invoices/9001.json' => invoiceXpressDocument(['type' => 'SimplifiedInvoice', 'status' => 'settled', 'inverted_sequence_number' => '2026/1', 'before_taxes' => 63.0, 'total' => 63.0], 'simplified_invoice'),
    ]);
    $draft = draftDocument(
        ['type' => FiscalDocumentType::SimplifiedInvoice, 'client_id' => null, 'party_name' => null, 'party_tax_id' => null, 'payment' => ['method' => 'cash', 'paid_on' => '2026-11-12']],
        [['description' => 'Consultoria de redes sociais', 'quantity' => 1000, 'unit_price' => 6300]],
    );
    InvoicingRecords::store($draft, new RemoteDocument('simplified_invoices', '9001', $draft->type, RemoteDocumentState::Draft, CarbonImmutable::parse('2026-11-12'), 6300, 0, 6300), IntegrationRecordState::InSync);

    $document = app(IssueFiscalDocument::class)->handle($draft);
    $income = OneOffIncome::query()->sole();

    expect($document->number)->toBe('FS 2026/1')
        ->and($income->title)->toBe('Consultoria de redes sociais')
        ->and($income->net_amount)->toBe(6300)
        ->and(Payment::query()->sole()->method->value)->toBe('cash')
        ->and($document->links->payment_id)->toBe(Payment::query()->sole()->id);
});

it('applies the credit to the invoice when the note says so', function () {
    $client = Client::factory()->create(['vat_number' => '508025338']);
    $receivable = Receivable::factory()->for(Project::factory()->for($client), 'billable')->create(['amount' => 29400]);
    $invoice = FiscalDocument::factory()->create(['client_id' => $client->id, 'party_tax_id' => '508025338', 'net_amount' => 29400, 'vat_amount' => 0, 'total_amount' => 29400, 'document_date' => '2026-11-10']);
    $invoice->links()->create(['receivable_id' => $receivable->id]);
    fakeInvoiceXpress([
        'GET /credit_notes/9001.json' => invoiceXpressDocument(['type' => 'CreditNote', 'status' => 'final', 'inverted_sequence_number' => '2026/3', 'before_taxes' => 54.0, 'total' => 54.0], 'credit_note'),
    ]);
    $note = draftDocument(
        ['type' => FiscalDocumentType::CreditNote, 'client_id' => $client->id, 'party_tax_id' => '508025338', 'reason' => 'O cliente desistiu do alojamento', 'receivable_effect' => 'apply'],
        [['description' => 'Alojamento anual', 'quantity' => 1000, 'unit_price' => 5400]],
    );
    $note->links()->create(['related_fiscal_document_id' => $invoice->id]);
    InvoicingRecords::store($note, new RemoteDocument('credit_notes', '9001', $note->type, RemoteDocumentState::Draft, CarbonImmutable::parse('2026-11-12'), 5400, 0, 5400), IntegrationRecordState::InSync);

    $action = app(IssueFiscalDocument::class);
    $issued = $action->handle($note);

    expect($issued->number)->toBe('NC 2026/3')
        ->and($action->warnings)->toBe([])
        ->and(CreditApplication::query()->sole()->amount)->toBe(5400)
        ->and($receivable->fresh()->outstanding)->toBe(24000);
});

it('issues a receipt against its invoice and records the payment', function () {
    $client = Client::factory()->create(['vat_number' => '508025338']);
    $receivable = Receivable::factory()->for(Project::factory()->for($client), 'billable')->create(['amount' => 18000]);
    $invoice = FiscalDocument::factory()->create(['client_id' => $client->id, 'net_amount' => 18000, 'vat_amount' => 0, 'total_amount' => 18000, 'number' => 'FT 2026/13']);
    $invoice->links()->create(['receivable_id' => $receivable->id]);
    InvoicingRecords::store($invoice, new RemoteDocument('invoices', '8800', $invoice->type, RemoteDocumentState::Issued, CarbonImmutable::parse('2026-10-28'), 18000, 0, 18000, 'FT 2026/13'), IntegrationRecordState::InSync);
    fakeInvoiceXpress([
        'POST /documents/8800/partial_payments.json' => invoiceXpressDocument(['id' => 9100, 'type' => 'Receipt', 'status' => 'final', 'inverted_sequence_number' => '2026/5', 'before_taxes' => 180.0, 'total' => 180.0], 'receipt'),
    ]);
    $receipt = FiscalDocument::factory()->draft()->create([
        'type' => FiscalDocumentType::Receipt, 'client_id' => $client->id, 'net_amount' => 18000, 'vat_amount' => 0, 'total_amount' => 18000,
        'payment' => ['method' => 'transfer', 'paid_on' => '2026-11-12'],
    ]);
    $receipt->links()->create(['related_fiscal_document_id' => $invoice->id]);

    $issued = app(IssueFiscalDocument::class)->handle($receipt);

    expect($issued->number)->toBe('RC 2026/5')
        ->and(Payment::query()->sole()->amount)->toBe(18000)
        ->and($receivable->fresh()->outstanding)->toBe(0);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->data()['partial_payment']['amount'] === '180.00'
        && $request->data()['partial_payment']['payment_mechanism'] === 'TB'
        && $request->data()['partial_payment']['serie'] === '2026');
});

it('does not send a receipt again after an attempt that may have gone through', function () {
    $invoice = FiscalDocument::factory()->create(['total_amount' => 18000, 'net_amount' => 18000, 'vat_amount' => 0]);
    InvoicingRecords::store($invoice, new RemoteDocument('invoices', '8800', $invoice->type, RemoteDocumentState::Issued, CarbonImmutable::parse('2026-10-28'), 18000, 0, 18000, 'FT 2026/13'), IntegrationRecordState::InSync);
    fakeInvoiceXpress(['POST /documents/8800/partial_payments.json' => fn () => Http::response('', 502)]);
    $receipt = FiscalDocument::factory()->draft()->create(['type' => FiscalDocumentType::Receipt, 'total_amount' => 18000, 'net_amount' => 18000, 'vat_amount' => 0, 'payment' => ['method' => 'transfer', 'paid_on' => '2026-11-12']]);
    $receipt->links()->create(['related_fiscal_document_id' => $invoice->id]);

    expect(fn () => app(IssueFiscalDocument::class)->handle($receipt))->toThrow(InvoicingProviderException::class);
    expect(fn () => app(IssueFiscalDocument::class)->handle($receipt))->toThrow(ValidationException::class);

    expect(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'))->toHaveCount(1);
});

it('does not issue when issuing from the app is turned off', function () {
    $this->connection->update(['settings' => ['issue_from_app' => false]]);
    Invoicing::forget();
    ($this->provider)();

    expect(fn () => app(IssueFiscalDocument::class)->handle(($this->draftAtProvider)()))->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

it('lets only the owner issue', function () {
    ($this->provider)();
    $draft = ($this->draftAtProvider)();
    $accountant = User::factory()->create(['email_verified_at' => now()]);
    $this->account->users()->attach($accountant, ['role' => AccountantAccess::ROLE]);
    $this->actingAs($accountant);

    expect(fn () => app(IssueFiscalDocument::class)->handle($draft))->toThrow(HttpException::class);
    Http::assertNothingSent();
});
