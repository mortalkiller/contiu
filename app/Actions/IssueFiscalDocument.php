<?php

namespace App\Actions;

use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Enums\IntegrationOperationStatus;
use App\Enums\IntegrationRecordState;
use App\Exceptions\InvoicingProviderException;
use App\Models\DirectInvoiceSale;
use App\Models\FiscalDocument;
use App\Models\Payment;
use App\Models\Receivable;
use App\Support\Invoicing\Invoicing;
use App\Support\Invoicing\InvoicingProvider;
use App\Support\Invoicing\InvoicingRecords;
use App\Support\Invoicing\RemoteDocument;
use App\Support\Money;
use App\Support\VatExemptionExport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Turns a draft into the official document: the provider issues it and the app keeps the number,
 * the series and the ATCUD it gives back. Asking twice issues once: a second request while the
 * first is running is refused, and one after it finds the document already issued.
 */
final class IssueFiscalDocument
{
    /**
     * What went wrong after the document was issued, such as a credit that could not be applied.
     * The document is issued all the same; these are for the user to finish by hand.
     *
     * @var list<string>
     */
    public array $warnings = [];

    public function __construct(private readonly SaveFiscalDocumentDraft $drafts) {}

    public function handle(FiscalDocument $draft): FiscalDocument
    {
        abort_unless(Invoicing::canIssue(), 403);
        $provider = Invoicing::provider() ?? throw ValidationException::withMessages(['document' => __('Connect an invoicing provider to issue documents.')]);

        if (! Invoicing::connection()->setting('issue_from_app', true)) {
            throw ValidationException::withMessages(['document' => __('Issuing from here is turned off. Issue the draft at :provider and synchronise.', ['provider' => $provider::label()])]);
        }

        $lock = Cache::lock("invoicing:issue:{$draft->getKey()}", 60);

        if (! $lock->get()) {
            throw ValidationException::withMessages(['document' => __('This document is already being issued.')]);
        }

        try {
            $document = FiscalDocument::query()->findOrFail($draft->getKey());

            if ($document->status === FiscalDocumentStatus::Active) {
                return $document;
            }

            if (! $document->isDraft()) {
                throw ValidationException::withMessages(['document' => __('Only a draft can be issued.')]);
            }

            return $this->complete($document, $this->issueAtProvider($document, $provider));
        } catch (InvoicingProviderException $exception) {
            InvoicingRecords::log('issued', IntegrationOperationStatus::Failed, __('Issue refused'), $draft, $exception->getMessage());

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    /**
     * Records in the app a document the provider has issued: here after issuing it, and from the
     * synchronisation when the draft was issued at the provider itself.
     */
    public function complete(FiscalDocument $document, RemoteDocument $remote): FiscalDocument
    {
        if (! $remote->isIssued() || $remote->number === null) {
            throw ValidationException::withMessages(['document' => __('The provider did not issue the document. Open it there and try again.')]);
        }

        $issued = DB::transaction(function () use ($document, $remote): FiscalDocument {
            $document = FiscalDocument::query()->lockForUpdate()->findOrFail($document->getKey());

            if ($document->status === FiscalDocumentStatus::Active) {
                return $document;
            }

            $document->forceFill([
                'status' => FiscalDocumentStatus::Active,
                'number' => $remote->number,
                'series' => $remote->series,
                'atcud' => $remote->atcud,
                'document_date' => $remote->date,
                'issued_at' => now(),
            ])->save();

            $same = $remote->matches($document->net_amount, $document->vat_amount, $document->total_amount);
            InvoicingRecords::store($document, $remote, $same ? IntegrationRecordState::InSync : IntegrationRecordState::Differs);
            InvoicingRecords::log('issued', IntegrationOperationStatus::Done, __(':number issued', ['number' => $remote->number]), $document);
            $this->applyEffects($document);

            return $document;
        });

        $this->warnAboutExport($issued);

        return $issued;
    }

    /**
     * An exempt account that may have just exported goods is reminded, after issuing, that the
     * art. 53.º exemption ends and the declaration of changes is due (IVA08, VAT05). The regime is
     * never switched here.
     */
    private function warnAboutExport(FiscalDocument $document): void
    {
        $warning = VatExemptionExport::issuedWarning($document);

        if ($warning !== null) {
            $this->warnings[] = $warning;
        }
    }

    private function issueAtProvider(FiscalDocument $document, InvoicingProvider $provider): RemoteDocument
    {
        if ($document->type === FiscalDocumentType::Receipt) {
            return $this->issueReceipt($document, $provider);
        }

        $record = InvoicingRecords::for($document);
        $remote = $record === null ? $this->drafts->send($document) : $provider->find($record->remote_type, $record->remote_id);

        // An earlier request got through before its answer was recorded: the document is issued, take it.
        if ($remote->isIssued()) {
            return $remote;
        }

        if (! $remote->matches($document->net_amount, $document->vat_amount, $document->total_amount)) {
            $remote = $this->drafts->send($document);
            $document->refresh();
        }

        if (! $remote->matches($document->net_amount, $document->vat_amount, $document->total_amount)) {
            throw ValidationException::withMessages(['document' => __(':provider adds this document up to :remote, not :local. Review it before issuing.', [
                'provider' => $provider::label(),
                'remote' => Money::format($remote->total),
                'local' => Money::format($document->total_amount),
            ])]);
        }

        return $provider->issue($document, $remote->remoteId);
    }

    /** A receipt has no draft at the provider: it is issued in one step, against its invoice there. */
    private function issueReceipt(FiscalDocument $receipt, InvoicingProvider $provider): RemoteDocument
    {
        $invoice = $receipt->links?->relatedFiscalDocument;
        $invoiceRemoteId = $invoice === null ? null : InvoicingRecords::remoteId($invoice);

        if ($invoiceRemoteId === null) {
            throw ValidationException::withMessages(['document' => __('The invoice of this receipt is not at :provider.', ['provider' => $provider::label()])]);
        }

        $pending = InvoicingRecords::pending("receipt:{$receipt->getKey()}", $receipt);

        if ($pending === null) {
            throw ValidationException::withMessages(['document' => __('An earlier attempt to issue this receipt did not finish. Synchronise before trying again, so it is not issued twice.')]);
        }

        try {
            $remote = $provider->settle($receipt, $invoiceRemoteId);
        } catch (InvoicingProviderException $exception) {
            // A plain refusal means nothing was issued, so trying again is safe. Anything else may have gone through.
            if ($exception->status !== null && $exception->status < 500) {
                $pending->update(['status' => IntegrationOperationStatus::Failed]);
            }

            throw $exception;
        }

        $pending->update(['status' => IntegrationOperationStatus::Done]);

        return $remote;
    }

    /**
     * What an issued document changes around it: what is owed, what was received. Also for an
     * invoice issued elsewhere and recorded here afterwards ("Registar como venda").
     */
    public function applyEffects(FiscalDocument $document): void
    {
        $link = $document->links()->firstOrCreate([]);

        match (true) {
            $document->type->settlesOnIssue() => $this->recordPayment($document, $link->receivable),
            $document->type === FiscalDocumentType::Invoice && $link->receivable_id === null && $document->client_id !== null => $link->update(['receivable_id' => $this->directSale($document)->getKey()]),
            $document->type === FiscalDocumentType::Receipt => $this->recordPayment($document, $link->relatedFiscalDocument?->links?->receivable),
            $document->type === FiscalDocumentType::CreditNote && $document->receivable_effect === 'apply' => $this->attempt(fn () => app(ManageCreditApplication::class)->apply($document->getKey())),
            $document->type === FiscalDocumentType::DebitNote && $document->receivable_effect === 'add' => $this->attempt(fn () => app(RecordDebitNoteCharge::class)->handle(
                $document->getKey(),
                ($document->due_date ?? $document->document_date)->toDateString(),
                $document->total_amount,
                (int) $link->related_fiscal_document_id,
            )),
            default => null,
        };
    }

    /** The money a fatura-recibo, a simplified invoice or a receipt says came in. */
    private function recordPayment(FiscalDocument $document, ?Receivable $receivable): void
    {
        $method = $document->payment['method'] ?? 'transfer';
        $paidOn = $document->payment['paid_on'] ?? $document->document_date->toDateString();
        $link = $document->links()->firstOrCreate([]);

        if ($receivable === null) {
            // Nothing was waiting to be collected: the sale and its payment are recorded together.
            $income = app(RecordOneOffIncome::class)->handle([
                'received_on' => $paidOn,
                'title' => Str::limit((string) ($document->lines()->value('description') ?? $document->type->getLabel()), 120, ''),
                'gross_amount' => $document->payableAmount(),
                'method' => $method,
                'invoice_number' => $document->number,
                'client_id' => $document->client_id,
            ]);
            $payment = $income->receivables()->first()?->payments()->first();
            $link->update(['payment_id' => $payment?->getKey()]);

            return;
        }

        $amount = min($document->payableAmount(), (int) $receivable->outstanding);

        if ($amount <= 0) {
            $this->warnings[] = __('The receivable was already settled, so no payment was recorded.');

            return;
        }

        $payment = Payment::create([
            'receivable_id' => $receivable->getKey(),
            'amount' => $amount,
            'paid_on' => $paidOn,
            'method' => $method,
            'invoice_number' => $document->number,
        ]);
        $link->update(['payment_id' => $payment->getKey()]);
    }

    /** An invoice issued with nothing to collect behind it creates what the client now owes. */
    private function directSale(FiscalDocument $document): Receivable
    {
        $sale = DirectInvoiceSale::query()->create([
            'fiscal_document_id' => $document->getKey(),
            'key' => (string) Str::uuid(),
            'client_id' => $document->client_id,
            'issued_on' => $document->document_date,
        ]);

        $receivable = $sale->receivables()->create([
            'description' => __('Invoice :number', ['number' => $document->number]),
            'amount' => $document->total_amount,
            'due_on' => $document->due_date ?? $document->document_date,
        ]);

        $sale->receivable()->associate($receivable)->save();

        return $receivable;
    }

    /** Runs a follow-up that has its own rules; when they refuse it, the document stays issued and the reason is kept. */
    private function attempt(callable $effect): void
    {
        try {
            $effect();
        } catch (ValidationException $exception) {
            $this->warnings[] = collect($exception->errors())->flatten()->first();
        }
    }
}
