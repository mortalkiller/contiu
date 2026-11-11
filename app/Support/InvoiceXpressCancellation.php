<?php

namespace App\Support;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Exceptions\InvoiceXpressException;
use App\Models\Account;
use App\Models\CreditApplication;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLink;
use App\Models\InvoiceXpressNoteAttempt;
use App\Models\InvoiceXpressSnapshot;
use App\Models\Payment;
use App\Models\Receivable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class InvoiceXpressCancellation
{
    public const int MAX_REASON_LENGTH = 255;

    private const TYPES = ['Invoice' => 'invoice', 'SimplifiedInvoice' => 'simplified_invoice'];

    public function __construct(
        private readonly InvoiceXpressClient $client,
        private readonly InvoiceXpressImport $import,
    ) {}

    public function canCancel(FiscalDocument $document): bool
    {
        return InvoiceXpressIssueRepository::ownerCanIssue()
            && $document->account_id === CurrentAccount::id()
            && Gate::allows('update', $document)
            && $this->isOriginalInvoice($document)
            && $document->invoiceXpressSnapshot !== null
            && InvoiceXpressIssue::canWriteDocuments();
    }

    public function cancel(int $documentId, string $reason, string $expectedSourceHash): FiscalDocument
    {
        abort_unless(InvoiceXpressIssueRepository::ownerCanIssue(), 403);

        $reason = trim($reason);
        Validator::make(['reason' => $reason], [
            'reason' => ['required', 'string', 'max:'.self::MAX_REASON_LENGTH, 'not_regex:/[\x00-\x1f\x7f]/u'],
        ], ['reason.*' => __('Enter a cancellation reason on one line, up to 255 characters.')])->validate();

        return DB::transaction(function () use ($documentId, $reason, $expectedSourceHash): FiscalDocument {
            $account = Account::query()->lockForUpdate()->findOrFail(CurrentAccount::id());
            abort_unless($account->hasAccess() && AccountantAccess::canManage($account), 403);

            if (! InvoiceXpressIssue::canWriteDocuments()) {
                throw $this->invalid(__('Cancellation is blocked until the InvoiceXpress account is approved for changes.'));
            }

            $document = FiscalDocument::query()->findOrFail($documentId);
            Gate::authorize('update', $document);

            // Payment and credit writers lock the receivable before modifying allocations.
            $chargeId = $document->links?->receivable_id;
            $charge = $chargeId === null ? null : Receivable::query()->lockForUpdate()->findOrFail($chargeId);
            $document = FiscalDocument::query()->lockForUpdate()->findOrFail($documentId);
            $this->assertUnencumbered($document, $charge);

            $subdomain = $this->client->configuration()->subdomain;
            $snapshot = $this->snapshot($document, $subdomain, $expectedSourceHash);
            $type = self::TYPES[$snapshot->source['provider_type']];
            $path = "/{$type}s/{$snapshot->provider_id}";
            [$original] = $this->readOriginal($snapshot, $type, $path);
            if ($original['status'] !== 'final') {
                throw $this->invalid(__('The invoice is already cancelled in InvoiceXpress. Confirm the update by importing it.'));
            }

            $related = $this->read("/document/{$snapshot->provider_id}/related_documents.json", $subdomain);

            if (($related['documents'] ?? null) !== []) {
                throw $this->invalid(__('InvoiceXpress did not confirm an empty list of related documents.'));
            }

            try {
                $this->client->request('PUT', $path.'/change-state.json',
                    payload: ['invoice' => ['state' => 'canceled', 'message' => $reason]],
                    expectedSubdomain: $subdomain,
                );
            } catch (InvoiceXpressException) {
                // The provider may have applied the PUT before the connection failed.
            }

            [$verified, $localHash] = $this->readOriginal($snapshot, $type, $path);
            if ($verified['status'] !== 'canceled') {
                throw $this->invalid(__('InvoiceXpress has yet to confirm the cancellation. The local document is still active.'));
            }

            if (($verified['reference'] ?? null) !== ($original['reference'] ?? null)) {
                throw $this->invalid(__('The invoice reference returned by InvoiceXpress has changed.'));
            }

            return $this->import->confirmInvoice(
                $verified,
                expectedCurrentHash: $snapshot->source_hash,
                expectedSubdomain: $subdomain,
                expectedLocalHash: $localHash,
            );
        });
    }

    private function isOriginalInvoice(FiscalDocument $document): bool
    {
        return $document->origin === 'InvoiceXpress'
            && $document->type === FiscalDocumentType::Invoice
            && $document->direction === FiscalDocumentDirection::Issued
            && $document->status === FiscalDocumentStatus::Active
            && $document->currency === 'EUR';
    }

    private function assertUnencumbered(FiscalDocument $document, ?Receivable $charge): void
    {
        $link = $document->links;
        $chargeId = $charge?->id;

        if (! $this->isOriginalInvoice($document)
            || $link?->receivable_id !== $chargeId
            || $link?->payment_id !== null || $link?->expense_id !== null || $link?->related_fiscal_document_id !== null
            || FiscalDocumentLink::query()->where('related_fiscal_document_id', $document->id)->exists()
            || InvoiceXpressNoteAttempt::query()->where('original_id', $document->id)->exists()
            || CreditApplication::query()->where('invoice_id', $document->id)->exists()
            || ($charge !== null && (
                $charge->payments()->exists()
                || Payment::query()->where('receivable_id', $chargeId)->exists()
                || $charge->creditApplications()->exists()
                || FiscalDocumentLink::query()->where('receivable_id', $chargeId)->where('fiscal_document_id', '!=', $document->id)->exists()
            ))) {
            throw $this->invalid(__('You can only cancel original InvoiceXpress invoices without payments, credits or other related documents.'));
        }
    }

    private function snapshot(FiscalDocument $document, string $subdomain, string $expectedSourceHash): InvoiceXpressSnapshot
    {
        $history = InvoiceXpressSnapshot::query()->where('fiscal_document_id', $document->id);
        $snapshot = (clone $history)->latest('id')->first();
        $providerType = $snapshot?->source['provider_type'] ?? null;

        if ($snapshot === null || $snapshot->subdomain !== $subdomain || $snapshot->provider_id < 1
            || ! is_string($providerType) || ! isset(self::TYPES[$providerType])
            || ! hash_equals($snapshot->source_hash, $expectedSourceHash)
            || $history->where(fn ($query) => $query->where('subdomain', '!=', $subdomain)
                ->orWhere('provider_id', '!=', $snapshot->provider_id))->exists()
            || InvoiceXpressSnapshot::query()->where('subdomain', $subdomain)
                ->where('provider_id', $snapshot->provider_id)->latest('id')->value('id') !== $snapshot->id) {
            throw $this->invalid(__('The source or version of the invoice has changed. Reopen the document to confirm.'));
        }

        return $snapshot;
    }

    /** @return array{array<string, mixed>, string} */
    private function readOriginal(InvoiceXpressSnapshot $snapshot, string $type, string $path): array
    {
        $invoice = $this->read($path.'.json', $snapshot->subdomain)[$type] ?? null;
        if (! is_array($invoice) || ($invoice['id'] ?? null) !== $snapshot->provider_id
            || ($invoice['type'] ?? null) !== $snapshot->source['provider_type']
            || ! in_array($invoice['status'] ?? null, ['final', 'canceled'], true)
            || (isset($invoice['reference']) && ! is_string($invoice['reference']))) {
            throw $this->invalid(__('InvoiceXpress did not return the original invoice in a state that could be verified for cancellation.'));
        }

        $review = $this->import->reviewInvoice($invoice, $snapshot->subdomain);
        $expected = $snapshot->source['document'] ?? null;
        $canceled = $invoice['status'] === 'canceled';

        if (! is_array($expected) || $review['before'] === null || $review['local_hash'] === null
            || $review['current_hash'] !== $snapshot->source_hash
            || $review['state'] !== ($canceled ? 'changed' : 'same')
            || Arr::sortRecursive($review['before']) !== Arr::sortRecursive($expected)
            || Arr::sortRecursive($review['after']) !== Arr::sortRecursive($canceled ? [
                ...$expected, 'status' => FiscalDocumentStatus::Voided->value,
            ] : $expected)) {
            throw $this->invalid(__('The invoice details returned by InvoiceXpress do not match the original document.'));
        }

        return [$invoice, $review['local_hash']];
    }

    /** @return array<string, mixed> */
    private function read(string $path, string $subdomain): array
    {
        try {
            return $this->client->request('GET', $path, expectedSubdomain: $subdomain);
        } catch (InvoiceXpressException) {
            throw $this->invalid(__('Could not confirm the status in InvoiceXpress. Review the invoice before trying again.'));
        }
    }

    private function invalid(string $message): ValidationException
    {
        return ValidationException::withMessages(['reason' => $message]);
    }
}
