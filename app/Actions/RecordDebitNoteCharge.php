<?php

namespace App\Actions;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Models\Account;
use App\Models\FiscalDocument;
use App\Models\Receivable;
use App\Support\AccountantAccess;
use App\Support\CurrentAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class RecordDebitNoteCharge
{
    public function handle(int $noteId, string $dueOn, int $expectedTotal, int $originalId): Receivable
    {
        $accountId = CurrentAccount::id();

        return DB::transaction(function () use ($accountId, $noteId, $dueOn, $expectedTotal, $originalId): Receivable {
            $account = Account::query()->lockForUpdate()->findOrFail($accountId);
            abort_unless($account->hasAccess() && AccountantAccess::canManage($account), 403);

            $documents = FiscalDocument::query()->whereKey([$noteId, $originalId])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $note = $documents->get($noteId) ?? abort(404);
            Gate::authorize('update', $note);
            $original = $documents->get($originalId);
            $link = $note->links()->lockForUpdate()->first();
            $party = $this->taxId($note->party_tax_id);

            if ($note->type !== FiscalDocumentType::DebitNote || ! $this->activeEuroDocument($note)
                || $note->total_amount <= 0 || $note->total_amount !== $expectedTotal
                || $note->net_amount + $note->vat_amount !== $note->total_amount
                || $original === null || $original->type !== FiscalDocumentType::Invoice
                || ! $this->activeEuroDocument($original) || $link?->related_fiscal_document_id !== $originalId
                || $note->document_date->lt($original->document_date)
                || in_array($party, ['', '999999990'], true) || $party !== $this->taxId($original->party_tax_id)) {
                throw ValidationException::withMessages(['due_on' => 'Revê os dados e a ligação da nota de débito à fatura original.']);
            }

            Validator::make(['due_on' => $dueOn], [
                'due_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$note->document_date->toDateString()],
            ], [
                'due_on.after_or_equal' => 'O vencimento deve ser igual ou posterior à data da nota de débito.',
            ])->validate();

            $originalCharge = Receivable::query()->excludingCancelled()->find($original->links?->receivable_id);
            $billable = $originalCharge?->billable;
            if ($billable === null || $party !== $this->taxId($originalCharge->client?->vat_number)) {
                throw ValidationException::withMessages(['due_on' => 'A fatura original deve estar ligada a um valor a receber ativo do mesmo cliente.']);
            }

            if ($link->receivable_id !== null) {
                $charge = $link->receivable;

                if ($charge === null || $charge->is($originalCharge) || $charge->amount !== $expectedTotal
                    || $charge->due_on->toDateString() !== $dueOn || ! $billable->is($charge->billable)) {
                    throw ValidationException::withMessages(['due_on' => 'A nota já está ligada a um valor a receber. Revê essa ligação.']);
                }

                return $charge;
            }

            $charge = $billable->receivables()->create([
                'description' => mb_substr('Nota de débito '.$note->number, 0, 255),
                'amount' => $expectedTotal,
                'due_on' => $dueOn,
            ]);
            $link->update(['receivable_id' => $charge->id]);

            return $charge;
        }, attempts: 3);
    }

    private function activeEuroDocument(FiscalDocument $document): bool
    {
        return $document->direction === FiscalDocumentDirection::Issued
            && $document->status === FiscalDocumentStatus::Active
            && $document->currency === 'EUR'
            && $document->document_date->lte(today());
    }

    private function taxId(?string $value): string
    {
        return preg_replace('/\APT(?=[0-9]{9}\z)/', '', strtoupper(trim($value ?? '')));
    }
}
