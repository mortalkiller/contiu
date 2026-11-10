<?php

namespace App\Actions;

use App\Enums\BankTransactionStatus;
use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Models\BankTransaction;
use App\Models\CreditApplication;
use App\Models\CustomerRefund;
use App\Models\FiscalDocument;
use App\Models\PaymentAllocation;
use App\Models\Receivable;
use App\Models\User;
use App\Support\CurrentAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class RecordCustomerRefund
{
    public function handle(int $noteId, int $amount, string $paidOn, string $reference, ?int $bankId = null): CustomerRefund
    {
        $account = CurrentAccount::get();
        $actor = auth()->user();
        abort_unless($account !== null && $actor instanceof User && $actor->hasVerifiedEmail()
            && $actor->canAccessTenant($account) && ! CurrentAccount::readOnly(), 403);
        $reference = trim($reference);
        Validator::make(['amount' => $amount, 'paid_on' => $paidOn, 'reference' => $reference], [
            'amount' => ['integer', 'min:1'],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reference' => ['required', 'string', 'max:120', 'not_regex:/\p{C}/u'],
        ])->validate();

        return DB::transaction(function () use ($noteId, $amount, $paidOn, $reference, $bankId, $actor): CustomerRefund {
            $note = FiscalDocument::query()->findOrFail($noteId);
            Gate::forUser($actor)->authorize('update', $note);
            $bank = $bankId === null ? null : BankTransaction::query()->lockForUpdate()->findOrFail($bankId);
            $invoice = $note->links?->relatedFiscalDocument;
            $charge = Receivable::query()->excludingCancelled()->lockForUpdate()->find($invoice?->links?->receivable_id);
            $documents = FiscalDocument::query()->whereKey([$note->id, $invoice?->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $note = $documents->get($noteId);
            $invoice = $documents->get($invoice?->id);

            if ($note === null || $invoice === null || $charge === null
                || $note->type !== FiscalDocumentType::CreditNote
                || ! in_array($invoice->type, [FiscalDocumentType::Invoice, FiscalDocumentType::InvoiceReceipt], true)
                || ! $this->eligible($note) || ! $this->eligible($invoice) || $invoice->total_amount <= 0
                || $note->links?->related_fiscal_document_id !== $invoice->id || $invoice->links?->receivable_id !== $charge->id
                || ($note->links->receivable_id !== null && $note->links->receivable_id !== $charge->id)
                || $note->document_date->lt($invoice->document_date) || $note->document_date->toDateString() > $paidOn
                || ($note->party_tax_id !== null && $invoice->party_tax_id !== null
                    && $this->party($note->party_tax_id) !== $this->party($invoice->party_tax_id))) {
                throw $this->invalid('Confirma a nota de crédito, a fatura original e a cobrança associada.');
            }

            $noteRefunds = CustomerRefund::query()->where('credit_note_id', $note->id);
            $refund = (clone $noteRefunds)->where('reference', $reference)->first();
            if ($refund !== null) {
                if ($refund->amount !== $amount || $refund->paid_on->toDateString() !== $paidOn
                    || $refund->invoice_id !== $invoice->id || $refund->receivable_id !== $charge->id
                    || ($bank !== null && $refund->bank_transaction_id !== null && $refund->bank_transaction_id !== $bank->id)) {
                    throw $this->invalid('Já existe um reembolso com esta referência e outros dados.');
                }
                if ($bank === null || $refund->bank_transaction_id === $bank->id) {
                    return $refund;
                }
            }

            if ($bank !== null && ($bank->status !== BankTransactionStatus::Pending || $bank->amount !== -$amount
                || $bank->booked_on->toDateString() !== $paidOn || $bank->links()->exists() || $bank->customerRefund()->exists())) {
                throw $this->invalid('Escolhe um débito por tratar com a mesma data e o mesmo montante do reembolso.');
            }

            if ($refund === null) {
                $this->validateAmount($note, $invoice, $charge, $amount, $paidOn, (int) $noteRefunds->sum('amount'));

                $refund = CustomerRefund::create([
                    'credit_note_id' => $note->id, 'invoice_id' => $invoice->id, 'receivable_id' => $charge->id,
                    'amount' => $amount, 'paid_on' => $paidOn, 'reference' => $reference, 'recorded_by' => $actor->id,
                ]);
            }

            if ($bank !== null) {
                $refund->update(['bank_transaction_id' => $bank->id]);
                $bank->update(['status' => BankTransactionStatus::Matched, 'difference_explanation' => null]);
            }

            return $refund;
        }, attempts: 3);
    }

    private function validateAmount(FiscalDocument $note, FiscalDocument $invoice, Receivable $charge, int $amount, string $paidOn, int $alreadyRefunded): void
    {
        $applications = CreditApplication::query()->whereNull('reversed_on');
        $invoiceCredits = (int) (clone $applications)->where('invoice_id', $invoice->id)->sum('amount');
        $invoiceRefunds = CustomerRefund::query()->where('invoice_id', $invoice->id);
        $chargeRefunds = CustomerRefund::query()->where('receivable_id', $charge->id);

        $cash = (int) PaymentAllocation::query()->where('receivable_id', $charge->id)->whereDate('allocated_on', '<=', $paidOn)
            ->whereHas('payment', fn (Builder $query): Builder => $query->whereDate('paid_on', '<=', $paidOn))->sum('amount');

        if ((clone $applications)->where('credit_note_id', $note->id)->exists()
            || (clone $chargeRefunds)->whereDate('paid_on', '>', $paidOn)->exists()
            || $amount > abs($note->total_amount) - $alreadyRefunded
            || $amount > $invoice->total_amount - $invoiceCredits - (int) $invoiceRefunds->sum('amount')
            || $amount > $cash - (int) $chargeRefunds->sum('amount')) {
            throw $this->invalid('Revê o saldo da nota, o dinheiro já recebido e as datas dos reembolsos anteriores.');
        }
    }

    private function eligible(FiscalDocument $document): bool
    {
        return $document->direction === FiscalDocumentDirection::Issued
            && $document->status === FiscalDocumentStatus::Active
            && $document->currency === 'EUR';
    }

    private function party(string $id): string
    {
        return preg_replace('/\APT(?=[0-9]{9}\z)/', '', strtoupper(trim($id)));
    }

    private function invalid(string $message): ValidationException
    {
        return ValidationException::withMessages(['amount' => $message]);
    }
}
