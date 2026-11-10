<?php

namespace App\Actions;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Models\CreditApplication;
use App\Models\CustomerRefund;
use App\Models\FiscalDocument;
use App\Models\Receivable;
use App\Models\User;
use App\Support\CurrentAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ManageCreditApplication
{
    public const MAX_REASON_LENGTH = 1000;

    /** @param array{total: int, invoice_id: int, receivable_id: int}|null $confirmation */
    public function apply(int $creditNoteId, ?array $confirmation = null): CreditApplication
    {
        return DB::transaction(function () use ($creditNoteId, $confirmation): CreditApplication {
            $note = FiscalDocument::query()->findOrFail($creditNoteId);
            $actor = $this->authorize($note);
            $invoice = $note->links?->relatedFiscalDocument;
            $receivableId = $invoice?->links?->receivable_id;

            if ($invoice === null || $receivableId === null || $invoice->is($note)) {
                throw ValidationException::withMessages(['credit' => 'Liga a nota à fatura original e a fatura ao valor a receber.']);
            }

            // Payments, split allocations and bank matches serialize on this same row.
            $receivable = Receivable::query()->excludingCancelled()->lockForUpdate()->find($receivableId);
            if ($receivable === null) {
                throw ValidationException::withMessages(['credit' => 'O valor a receber da fatura já não está disponível.']);
            }

            $documents = FiscalDocument::query()->whereKey([$note->id, $invoice->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $note = $documents->get($note->id);
            $invoice = $documents->get($invoice->id);

            if ($note === null || $invoice === null
                || ! $this->isEligible($note, FiscalDocumentType::CreditNote)
                || ! $this->isEligible($invoice, FiscalDocumentType::Invoice)
                || (int) $note->links?->related_fiscal_document_id !== $invoice->id
                || (int) $invoice->links?->receivable_id !== $receivable->id
                || ($note->links->receivable_id !== null && (int) $note->links->receivable_id !== $receivable->id)
                || $note->document_date->isBefore($invoice->document_date)) {
                throw ValidationException::withMessages(['credit' => 'Confirma uma nota de crédito e uma fatura emitidas, ativas, em EUR e ligadas ao mesmo valor a receber.']);
            }

            if ($confirmation !== null && (
                $confirmation['total'] !== $note->total_amount
                || $confirmation['invoice_id'] !== $invoice->id
                || $confirmation['receivable_id'] !== $receivable->id
            )) {
                throw ValidationException::withMessages(['credit' => 'O valor ou a ligação mudou. Fecha esta janela e confirma novamente a aplicação.']);
            }

            $active = CreditApplication::query()->whereNull('reversed_on');
            if ((clone $active)->where('credit_note_id', $note->id)->exists()) {
                throw ValidationException::withMessages(['credit' => 'Esta nota de crédito já está aplicada.']);
            }

            if (CustomerRefund::query()->where('credit_note_id', $note->id)->exists()) {
                throw ValidationException::withMessages(['credit' => 'Esta nota de crédito já tem reembolsos registados.']);
            }

            $availableOnInvoice = $invoice->total_amount - (int) $active->where('invoice_id', $invoice->id)->sum('amount')
                - (int) CustomerRefund::query()->where('invoice_id', $invoice->id)->sum('amount');
            $amount = abs($note->total_amount);

            if ($amount <= 0 || $amount > $availableOnInvoice || $amount > $receivable->outstanding) {
                throw ValidationException::withMessages(['credit' => 'O total da nota de crédito deve caber no saldo da fatura e no valor em aberto.']);
            }

            return CreditApplication::create([
                'receivable_id' => $receivable->id,
                'invoice_id' => $invoice->id,
                'credit_note_id' => $note->id,
                'amount' => $amount,
                'applied_on' => today(),
                'applied_by' => $actor->id,
            ]);
        }, attempts: 3);
    }

    public function reverse(int $creditNoteId, int $applicationId, string $reason): void
    {
        DB::transaction(function () use ($creditNoteId, $applicationId, $reason): void {
            $note = FiscalDocument::query()->findOrFail($creditNoteId);
            $actor = $this->authorize($note);
            $applicationQuery = CreditApplication::query()->where('credit_note_id', $note->id)->whereKey($applicationId);
            $application = (clone $applicationQuery)->firstOrFail();

            Receivable::query()->lockForUpdate()->findOrFail($application->receivable_id);
            FiscalDocument::query()->lockForUpdate()->findOrFail($note->id);
            $application = $applicationQuery->lockForUpdate()->firstOrFail();

            if ($application->reversed_on !== null) {
                throw ValidationException::withMessages(['credit' => 'Esta aplicação já foi revertida.']);
            }

            $reason = trim($reason);
            Validator::make(['reason' => $reason], ['reason' => 'required|string|max:'.self::MAX_REASON_LENGTH])->validate();

            $application->update([
                'reversed_on' => today(),
                'reversed_by' => $actor->id,
                'reversal_reason' => $reason,
            ]);
        }, attempts: 3);
    }

    private function authorize(FiscalDocument $note): User
    {
        $account = CurrentAccount::get();
        $user = auth()->user();

        abort_unless($account !== null && $user instanceof User && $user->hasVerifiedEmail() && $user->canAccessTenant($account) && ! CurrentAccount::readOnly(), 403);
        Gate::forUser($user)->authorize('update', $note);

        return $user;
    }

    private function isEligible(FiscalDocument $document, FiscalDocumentType $type): bool
    {
        return $document->type === $type
            && $document->direction === FiscalDocumentDirection::Issued
            && $document->status === FiscalDocumentStatus::Active
            && $document->currency === 'EUR'
            && ! $document->document_date->isAfter(today());
    }
}
