<?php

use App\Actions\CancelFiscalDocument;
use App\Enums\FiscalDocumentStatus;
use App\Enums\IntegrationRecordState;
use App\Models\FiscalDocument;
use App\Models\IntegrationRecord;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Support\Invoicing\InvoicingRecords;
use App\Support\Invoicing\RemoteDocument;
use App\Support\Invoicing\RemoteDocumentState;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

pest()->group('invoicing', 'fiscal');

/*
 * Cancelling an issued document and throwing away a draft. A document with payments, credits or
 * notes is never cancelled: it is corrected with a credit note.
 */

beforeEach(function () {
    $this->actingAs(ownerOf($this->account));
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->account);
    invoicingConnection();

    $this->issued = function (): FiscalDocument {
        $document = FiscalDocument::factory()->create(['number' => 'FT 2026/15', 'net_amount' => 15000, 'vat_amount' => 0, 'total_amount' => 15000]);
        InvoicingRecords::store($document, new RemoteDocument('invoices', '9001', $document->type, RemoteDocumentState::Issued, CarbonImmutable::parse('2026-11-12'), 15000, 0, 15000, 'FT 2026/15'), IntegrationRecordState::InSync);

        return $document;
    };
    $this->provider = function (): void {
        $cancelled = false;
        fakeInvoiceXpress([
            'GET /invoices/9001.json' => function () use (&$cancelled) {
                return Http::response(invoiceXpressDocument(['status' => $cancelled ? 'canceled' : 'final', 'inverted_sequence_number' => '2026/15']));
            },
            'PUT /invoices/9001/change-state.json' => function () use (&$cancelled) {
                $cancelled = true;

                return Http::response('', 200);
            },
        ]);
    };
});

it('cancels the document at the provider and voids it here', function () {
    ($this->provider)();
    $document = ($this->issued)();

    $cancelled = app(CancelFiscalDocument::class)->handle($document, 'Emitida por engano');

    expect($cancelled->status)->toBe(FiscalDocumentStatus::Voided)
        ->and($cancelled->number)->toBe('FT 2026/15')
        ->and(InvoicingRecords::for($cancelled)->remote['state'])->toBe('canceled');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->data() === ['invoice' => ['state' => 'canceled', 'message' => 'Emitida por engano']]);
});

it('refuses to cancel a document that already has a payment', function () {
    ($this->provider)();
    $document = ($this->issued)();
    $receivable = Receivable::factory()->for(Project::factory(), 'billable')->create(['amount' => 15000]);
    $document->links()->create(['receivable_id' => $receivable->id]);
    Payment::create(['receivable_id' => $receivable->id, 'amount' => 5000, 'paid_on' => today(), 'method' => 'transfer']);

    expect(fn () => app(CancelFiscalDocument::class)->handle($document, 'Engano'))->toThrow(ValidationException::class);

    expect($document->fresh()->status)->toBe(FiscalDocumentStatus::Active);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
});

it('asks for a reason on a single line', function (string $reason) {
    ($this->provider)();

    expect(fn () => app(CancelFiscalDocument::class)->handle(($this->issued)(), $reason))->toThrow(ValidationException::class);
    Http::assertNothingSent();
})->with(['empty' => [''], 'two lines' => ["um\ndois"], 'too long' => [str_repeat('a', 256)]]);

it('throws a draft away here and at the provider', function () {
    fakeInvoiceXpress(['PUT /invoices/9001/change-state.json' => []]);
    $draft = draftDocument();
    InvoicingRecords::store($draft, new RemoteDocument('invoices', '9001', $draft->type, RemoteDocumentState::Draft, CarbonImmutable::parse('2026-11-12'), 15000, 0, 15000), IntegrationRecordState::InSync);

    app(CancelFiscalDocument::class)->discard($draft);

    expect(FiscalDocument::query()->count())->toBe(0)->and(IntegrationRecord::query()->where('remote_type', 'invoices')->count())->toBe(0);
    Http::assertSent(fn (Request $request): bool => $request->data() === ['invoice' => ['state' => 'deleted']]);
});

it('never deletes an issued document', function () {
    fakeInvoiceXpress();

    expect(fn () => app(CancelFiscalDocument::class)->discard(($this->issued)()))->toThrow(ValidationException::class);
    expect(FiscalDocument::query()->count())->toBe(1);
});
