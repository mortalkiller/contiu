<?php

namespace App\Actions;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\IntegrationOperationStatus;
use App\Enums\IntegrationRecordState;
use App\Models\Account;
use App\Models\CreditApplication;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLink;
use App\Models\Payment;
use App\Models\Receivable;
use App\Support\CurrentAccount;
use App\Support\Invoicing\Invoicing;
use App\Support\Invoicing\InvoicingRecords;
use App\Support\Invoicing\RemoteDocumentState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Cancels an issued document at the provider and voids it here, or throws away a draft. Only a
 * document nothing else depends on can go: once it has payments, credits or notes, it is
 * corrected with a credit note instead.
 */
final class CancelFiscalDocument
{
    public const int MAX_REASON_LENGTH = 255;

    /** Whether the screen should offer to cancel: the cheap checks, without asking the provider. */
    public function canCancel(FiscalDocument $document): bool
    {
        return Invoicing::canIssue()
            && $document->direction === FiscalDocumentDirection::Issued
            && $document->status === FiscalDocumentStatus::Active
            && ! $document->type->requiresOriginal()
            && $document->integrationRecord !== null;
    }

    public function handle(FiscalDocument $document, string $reason): FiscalDocument
    {
        abort_unless(Invoicing::canIssue(), 403);
        $provider = Invoicing::provider() ?? throw $this->invalid(__('Connect an invoicing provider first.'));
        $reason = trim($reason);

        Validator::make(['reason' => $reason], [
            'reason' => ['required', 'string', 'max:'.self::MAX_REASON_LENGTH, 'not_regex:/[\x00-\x1f\x7f]/u'],
        ], ['reason.*' => __('Enter a cancellation reason on one line, up to 255 characters.')])->validate();

        return DB::transaction(function () use ($document, $reason, $provider): FiscalDocument {
            Account::query()->lockForUpdate()->findOrFail(CurrentAccount::id());
            abort_unless(Invoicing::canIssue(), 403);

            // Payments and credits lock the receivable before they touch it, so this goes first.
            $chargeId = $document->links()->value('receivable_id');
            $charge = $chargeId === null ? null : Receivable::query()->lockForUpdate()->find($chargeId);
            $document = FiscalDocument::query()->lockForUpdate()->findOrFail($document->getKey());
            $entry = InvoicingRecords::for($document);

            if (! $this->canCancel($document) || $entry === null) {
                throw $this->invalid(__('Only an issued document of the connected provider can be cancelled.'));
            }

            $this->assertUnencumbered($document, $charge);

            $remote = $provider->find($entry->remote_type, $entry->remote_id);

            if ($remote->state !== RemoteDocumentState::Canceled) {
                $remote = $provider->cancel($document, $entry->remote_id, $reason);
            }

            if ($remote->state !== RemoteDocumentState::Canceled) {
                throw $this->invalid(__('The provider has yet to confirm the cancellation. The document is still active.'));
            }

            $document->forceFill(['status' => FiscalDocumentStatus::Voided])->save();
            InvoicingRecords::store($document, $remote, IntegrationRecordState::InSync);
            InvoicingRecords::log('cancelled', IntegrationOperationStatus::Done, __(':number cancelled', ['number' => $document->number]), $document, $reason);

            return $document;
        });
    }

    /** Throws away a draft, here and at the provider. */
    public function discard(FiscalDocument $draft): void
    {
        abort_unless(Invoicing::canIssue(), 403);

        DB::transaction(function () use ($draft): void {
            $draft = FiscalDocument::query()->lockForUpdate()->findOrFail($draft->getKey());

            if (! $draft->isDraft()) {
                throw $this->invalid(__('Only a draft can be deleted. Cancel an issued document instead.'));
            }

            $entry = InvoicingRecords::for($draft);

            if ($entry !== null) {
                Invoicing::provider()?->discard($draft, $entry->remote_id);
                $entry->delete();
            }

            $draft->delete();
        });
    }

    private function assertUnencumbered(FiscalDocument $document, ?Receivable $charge): void
    {
        $link = $document->links;

        if (FiscalDocumentLink::query()->where('related_fiscal_document_id', $document->getKey())->exists()
            || CreditApplication::query()->where('invoice_id', $document->getKey())->exists()
            || $link?->payment_id !== null
            || ($charge !== null && (
                $charge->payments()->exists()
                || Payment::query()->where('receivable_id', $charge->getKey())->exists()
                || $charge->creditApplications()->exists()
            ))) {
            throw $this->invalid(__('A document with payments, credits or notes cannot be cancelled. Correct it with a credit note.'));
        }
    }

    private function invalid(string $message): ValidationException
    {
        return ValidationException::withMessages(['reason' => $message]);
    }
}
