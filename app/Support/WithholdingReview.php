<?php

namespace App\Support;

use App\Enums\BankTransactionStatus;
use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Exceptions\AccountIsReadOnly;
use App\Models\Account;
use App\Models\FiscalDocument;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Receivable;
use App\Models\WithholdingRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** IRS withholding supported by a receipt, reported by payment date in integer EUR cents. */
final readonly class WithholdingReview
{
    /** @param Collection<int, array{record: WithholdingRecord, current: bool, paymentAmount: int, cashAmount: int, bankCredit: ?int}> $rows */
    private function __construct(
        public ?int $retainedTotal,
        public Collection $rows,
    ) {}

    public static function forYear(int $year): self
    {
        $start = Period::of($year)->start;
        $rows = WithholdingRecord::query()
            ->where('paid_on', '>=', $start->toDateString())
            ->where('paid_on', '<', $start->addYear()->toDateString())
            ->with(['document.links', 'payment.bankLink.bankTransaction' => fn ($query) => $query->withCount('links')])
            ->orderBy('paid_on')
            ->orderBy('id')
            ->get()
            ->filter(fn (WithholdingRecord $record): bool => $record->document !== null && $record->payment !== null);
        $allocationRecords = $rows->whereNotNull('payment_allocation_id')->modelKeys();
        $confirmed = $allocationRecords === [] ? [] : self::confirmedAllocations()
            ->whereIntegerInRaw('withholding_records.id', $allocationRecords)
            ->pluck('withholding_records.id')->flip()->all();

        $rows = $rows->map(function (WithholdingRecord $record) use ($confirmed): array {
            $payment = $record->payment;
            $document = $record->document;
            $current = $record->payment_allocation_id !== null
                ? isset($confirmed[$record->id])
                : $document->links?->payment_id == $payment->id && $record->source_hash === self::sourceHash($document, $payment);

            return [
                'record' => $record,
                'current' => $current,
                'paymentAmount' => $payment->amount,
                'cashAmount' => $record->gross_amount - $record->retained_amount,
                'bankCredit' => self::bankCredit($payment),
            ];
        });

        $current = $rows->where('current', true);

        return new self($current->isEmpty() ? null : $current->sum('record.retained_amount'), $rows);
    }

    /** @return Collection<int, FiscalDocument> */
    public static function sourcesForYear(int $year): Collection
    {
        $start = Period::of($year)->start;

        return self::sourceDocuments()
            ->whereHas('links.payment', fn (Builder $query) => $query
                ->where('paid_on', '>=', $start->toDateString())
                ->where('paid_on', '<', $start->addYear()->toDateString()))
            ->with('links.payment.bankLink.bankTransaction', fn ($query) => $query->withCount('links'))
            ->orderByDesc('document_date')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (FiscalDocument $document): bool => $document->links?->payment !== null);
    }

    public static function bankCredit(Payment $payment): ?int
    {
        $bank = $payment->bankLink?->bankTransaction;

        return $bank !== null && $bank->status === BankTransactionStatus::Matched && $bank->amount > 0
            && ($bank->links_count ?? $bank->links()->count()) === 1
            ? $bank->amount
            : null;
    }

    public static function record(int $documentId, int $retained, ?int $year = null, ?int $allocationId = null): void
    {
        if (CurrentAccount::readOnly()) {
            throw new AccountIsReadOnly;
        }

        DB::transaction(function () use ($documentId, $retained, $year, $allocationId): void {
            Account::query()->lockForUpdate()->findOrFail(CurrentAccount::id());
            $document = self::sourceDocuments()->lockForUpdate()->find($documentId);
            if ($document === null) {
                throw self::invalid('documentId', 'Escolhe um recibo ou uma fatura-recibo emitida, ativa e em EUR.');
            }

            self::validateAmounts($document, $retained);
            $record = WithholdingRecord::query()->where('fiscal_document_id', $documentId)->first();
            $allocationId ??= $record?->payment_allocation_id;
            if ($allocationId !== null) {
                self::reconcileAllocation($document, $retained, $year, $allocationId, $record);

                return;
            }

            $payment = $document->links?->payment()->lockForUpdate()->first();
            if ($payment === null || ($year !== null && $payment->paid_on->year !== $year)) {
                throw self::invalid('documentId', 'Escolhe um recibo ligado a um recebimento do ano.');
            }

            if ($payment->is_split) {
                throw self::invalid('documentId', 'Escolhe a afetação a que pertence este recibo antes de registar a retenção.');
            }

            $allocation = $payment->allocations()->first();
            if ($allocation !== null && auth()->check() && self::original($allocation) !== null) {
                self::reconcileAllocation($document, $retained, $year, $allocation->id, $record);

                return;
            }

            self::validateCash($document, $retained, $payment->amount);

            if (WithholdingRecord::query()->where('payment_id', $payment->id)->where('fiscal_document_id', '!=', $documentId)->exists()) {
                throw self::invalid('documentId', 'Já existe retenção registada para este recebimento.');
            }

            WithholdingRecord::query()->updateOrCreate(['fiscal_document_id' => $documentId], self::recordData($document, $payment, $retained));
        }, attempts: 3);
    }

    /**
     * Confirmed receipt-to-allocation evidence for the current account. Kept in SQL so a stale
     * receipt, link or cash allocation is excluded consistently from balances and review screens.
     */
    public static function confirmedAllocations(): QueryBuilder
    {
        return DB::table('withholding_records')
            ->join('payment_allocations as allocation', fn (JoinClause $join) => $join
                ->on('allocation.account_id', '=', 'withholding_records.account_id')
                ->on('allocation.id', '=', 'withholding_records.payment_allocation_id')
                ->on('allocation.payment_id', '=', 'withholding_records.payment_id')
                ->on('allocation.receivable_id', '=', 'withholding_records.receivable_id'))
            ->join('payments as cash', fn (JoinClause $join) => $join
                ->on('cash.account_id', '=', 'withholding_records.account_id')
                ->on('cash.id', '=', 'withholding_records.payment_id'))
            ->join('receivables as charge', fn (JoinClause $join) => $join
                ->on('charge.account_id', '=', 'withholding_records.account_id')
                ->on('charge.id', '=', 'withholding_records.receivable_id'))
            ->join('fiscal_documents as receipt', fn (JoinClause $join) => $join
                ->on('receipt.account_id', '=', 'withholding_records.account_id')
                ->on('receipt.id', '=', 'withholding_records.fiscal_document_id'))
            ->join('fiscal_document_links as receipt_link', fn (JoinClause $join) => $join
                ->on('receipt_link.account_id', '=', 'receipt.account_id')
                ->on('receipt_link.fiscal_document_id', '=', 'receipt.id')
                ->on('receipt_link.payment_id', '=', 'cash.id')
                ->on('receipt_link.receivable_id', '=', 'charge.id'))
            ->join('fiscal_documents as original', fn (JoinClause $join) => $join
                ->on('original.account_id', '=', 'withholding_records.account_id')
                ->on('original.id', '=', 'withholding_records.original_document_id'))
            ->join('fiscal_document_links as original_link', fn (JoinClause $join) => $join
                ->on('original_link.account_id', '=', 'original.account_id')
                ->on('original_link.fiscal_document_id', '=', 'original.id')
                ->on('original_link.receivable_id', '=', 'charge.id'))
            ->where('withholding_records.account_id', CurrentAccount::id())
            ->where('receipt.status', FiscalDocumentStatus::Active->value)
            ->where('original.status', FiscalDocumentStatus::Active->value)
            ->where('receipt.direction', FiscalDocumentDirection::Issued->value)
            ->where('original.direction', FiscalDocumentDirection::Issued->value)
            ->where('receipt.currency', 'EUR')
            ->where('original.currency', 'EUR')
            ->whereNull('cash.advance_client_id')
            ->whereNull('receipt_link.expense_id')
            ->whereRaw('date(cash.paid_on) = date(withholding_records.paid_on)')
            ->whereRaw('date(allocation.allocated_on) = date(withholding_records.paid_on)')
            ->whereColumn('receipt.total_amount', 'withholding_records.gross_amount')
            ->whereColumn('receipt.net_amount', 'withholding_records.document_net_amount')
            ->whereRaw('date(receipt.document_date) = date(withholding_records.document_date)')
            ->whereColumn('receipt.number', 'withholding_records.document_number')
            ->whereColumn('charge.amount', 'withholding_records.receivable_amount')
            ->whereRaw('date(original.document_date) <= date(cash.paid_on)')
            ->whereRaw('receipt.net_amount + receipt.vat_amount = receipt.total_amount')
            ->whereRaw('allocation.amount + withholding_records.retained_amount = withholding_records.gross_amount')
            ->where(fn (QueryBuilder $query) => $query
                ->where(fn (QueryBuilder $query) => $query->where('receipt.type', FiscalDocumentType::Receipt->value)
                    ->where('original.type', FiscalDocumentType::Invoice->value)->whereColumn('receipt_link.related_fiscal_document_id', 'original.id'))
                ->orWhere(fn (QueryBuilder $query) => $query->where('receipt.type', FiscalDocumentType::InvoiceReceipt->value)
                    ->whereColumn('receipt.id', 'original.id')->whereNull('receipt_link.related_fiscal_document_id')))
            ->where(fn (QueryBuilder $query) => $query
                ->where(fn (QueryBuilder $query) => $query->where('withholding_records.settles_receivable', true)
                    ->whereRaw('original.net_amount + original.vat_amount = charge.amount'))
                ->orWhere(fn (QueryBuilder $query) => $query->where('withholding_records.settles_receivable', false)
                    ->whereColumn('charge.amount', 'allocation.amount')
                    ->whereRaw('original.net_amount + original.vat_amount = receipt.total_amount')));
    }

    public static function settlementsOn(string $day): QueryBuilder
    {
        return self::confirmedAllocations()
            ->where('withholding_records.settles_receivable', true)
            ->whereDate('withholding_records.paid_on', '<=', $day);
    }

    /** @return Collection<int, FiscalDocument> */
    public static function sourcesForAllocation(PaymentAllocation $allocation): Collection
    {
        $invoice = self::original($allocation);
        if ($invoice === null) {
            return collect();
        }

        return self::sourceDocuments()
            ->where(fn (Builder $query) => $query->where('type', FiscalDocumentType::Receipt)->orWhereKey($invoice->id))
            ->whereBetween('total_amount', [$allocation->amount + 1, $invoice->net_amount + $invoice->vat_amount])
            ->where('document_date', '>=', $invoice->document_date->toDateString())
            ->with('links')
            ->orderByDesc('document_date')->orderByDesc('id')->get()
            ->filter(fn (FiscalDocument $document): bool => self::compatibleLink($document, $allocation, $invoice));
    }

    private static function reconcileAllocation(FiscalDocument $document, int $retained, ?int $year, int $allocationId, ?WithholdingRecord $record): void
    {
        $allocation = PaymentAllocation::query()->find($allocationId);
        $receivable = $allocation?->receivable()->excludingCancelled()->lockForUpdate()->first();
        $payment = $allocation?->payment()->lockForUpdate()->first();
        $allocation = $payment?->allocations()->where('receivable_id', $receivable?->id)->lockForUpdate()->find($allocationId);
        if ($allocation === null || $receivable === null || $payment->isAdvance() || ! $allocation->allocated_on->isSameDay($payment->paid_on)
            || ($year !== null && $payment->paid_on->year !== $year)) {
            throw self::invalid('documentId', 'A afetação deve corresponder a um recebimento do ano, aplicado na data em que foi recebido.');
        }

        abort_unless(Gate::allows('update', $document) && Gate::allows('view', $payment), 403);
        $invoice = self::original($allocation, lock: true);
        if ($invoice === null || $invoice->document_date->isAfter($payment->paid_on) || $document->document_date->lt($invoice->document_date)
            || ! self::compatibleLink($document, $allocation, $invoice)) {
            throw self::invalid('documentId', 'Confere o recibo e a ligação à fatura original desta cobrança.');
        }

        self::validateCash($document, $retained, $allocation->amount);
        if (($record?->payment_allocation_id !== null && $record->payment_allocation_id !== $allocation->id)
            || WithholdingRecord::query()->where('fiscal_document_id', '!=', $document->id)
                ->where(fn (Builder $query) => $query->where('payment_allocation_id', $allocation->id)
                    ->orWhere(fn (Builder $query) => $query->where('payment_id', $payment->id)->whereNull('payment_allocation_id')))
                ->exists()) {
            throw self::invalid('documentId', 'Já existe retenção registada para esta afetação ou para o recebimento inteiro.');
        }

        $invoiceGross = $invoice->net_amount + $invoice->vat_amount;
        $settles = $receivable->amount === $invoiceGross;
        if ($document->total_amount > $invoiceGross || (! $settles
            && ($receivable->amount !== $allocation->amount || $document->total_amount !== $invoiceGross))) {
            throw self::invalid('documentId', 'O bruto da fatura tem de coincidir com a cobrança, ou o recibo tem de comprovar a liquidação integral de uma cobrança já pelo líquido.');
        }

        if ($settles) {
            self::checkBalance($receivable, $payment->paid_on->toDateString(), $retained, $document->id);
        }

        $document->links()->updateOrCreate([], [
            'payment_id' => $payment->id,
            'receivable_id' => $receivable->id,
            'related_fiscal_document_id' => $document->id === $invoice->id ? null : $invoice->id,
        ]);
        $document->unsetRelation('links');

        WithholdingRecord::query()->updateOrCreate(['fiscal_document_id' => $document->id], [
            ...self::recordData($document, $payment, $retained),
            'payment_allocation_id' => $allocation->id,
            'receivable_id' => $receivable->id,
            'original_document_id' => $invoice->id,
            'receivable_amount' => $receivable->amount,
            'document_net_amount' => $document->net_amount,
            'document_date' => $document->document_date->toDateString(),
            'document_number' => $document->number,
            'settles_receivable' => $settles,
        ]);
    }

    private static function checkBalance(Receivable $receivable, string $paidOn, int $retained, int $documentId): void
    {
        foreach (array_unique([$paidOn, max($paidOn, today()->toDateString())]) as $day) {
            $cash = PaymentAllocation::query()->where('receivable_id', $receivable->id)->whereDate('allocated_on', '<=', $day)->sum('amount');
            $credits = $receivable->creditApplications()->effectiveAt($day)->sum('amount');
            $otherRetention = self::settlementsOn($day)->where('withholding_records.receivable_id', $receivable->id)
                ->where('withholding_records.fiscal_document_id', '!=', $documentId)->sum('withholding_records.retained_amount');

            if ($retained > $receivable->amount - $cash - $credits - $otherRetention) {
                throw self::invalid('retained', 'A retenção excede o saldo por regularizar desta cobrança.');
            }
        }
    }

    private static function original(PaymentAllocation $allocation, bool $lock = false): ?FiscalDocument
    {
        $documents = FiscalDocument::query()->where('direction', FiscalDocumentDirection::Issued)
            ->where('status', FiscalDocumentStatus::Active)->where('currency', 'EUR')
            ->whereIn('type', [FiscalDocumentType::Invoice, FiscalDocumentType::InvoiceReceipt])
            ->whereHas('links', fn (Builder $query) => $query->where('receivable_id', $allocation->receivable_id))
            ->when($lock, fn (Builder $query) => $query->lockForUpdate())->limit(2)->get();

        return $documents->count() === 1 ? $documents->sole() : null;
    }

    private static function compatibleLink(FiscalDocument $document, PaymentAllocation $allocation, FiscalDocument $invoice): bool
    {
        if (($document->type === FiscalDocumentType::InvoiceReceipt) !== ($document->id === $invoice->id)) {
            return false;
        }

        $link = $document->links;
        $taxId = static fn (?string $value): string => preg_replace('/\APT(?=[0-9]{9}\z)/', '', strtoupper(trim($value ?? '')));
        $receiptParty = $taxId($document->party_tax_id);
        $invoiceParty = $taxId($invoice->party_tax_id);

        return ($link?->payment_id === null || (int) $link->payment_id === $allocation->payment_id)
            && ($link?->receivable_id === null || (int) $link->receivable_id === $allocation->receivable_id)
            && ($link?->related_fiscal_document_id === null || ($document->id !== $invoice->id && (int) $link->related_fiscal_document_id === $invoice->id))
            && $link?->expense_id === null
            && ($receiptParty === '' || $invoiceParty === '' || $receiptParty === $invoiceParty);
    }

    private static function validateAmounts(FiscalDocument $document, int $retained): void
    {
        if ($document->net_amount <= 0 || $document->vat_amount < 0 || $document->total_amount - $document->net_amount !== $document->vat_amount) {
            throw self::invalid('documentId', 'Confere o valor antes de IVA, o IVA e o total antes da retenção no documento.');
        }

        if ($retained <= 0 || $retained > $document->net_amount) {
            throw self::invalid('retained', 'A retenção deve ser positiva e não pode exceder o valor do documento antes de IVA.');
        }
    }

    private static function validateCash(FiscalDocument $document, int $retained, int $cash): void
    {
        if ($cash <= 0 || $document->total_amount - $retained !== $cash) {
            throw self::invalid('retained', 'O total antes da retenção, menos o valor retido, deve coincidir ao cêntimo com o valor recebido.');
        }
    }

    private static function recordData(FiscalDocument $document, Payment $payment, int $retained): array
    {
        return [
            'payment_id' => $payment->id,
            'paid_on' => $payment->paid_on->toDateString(),
            'gross_amount' => $document->total_amount,
            'retained_amount' => $retained,
            'source_hash' => self::sourceHash($document, $payment),
        ];
    }

    private static function invalid(string $field, string $message): ValidationException
    {
        return ValidationException::withMessages([$field => $message]);
    }

    /** @return Builder<FiscalDocument> */
    private static function sourceDocuments(): Builder
    {
        return FiscalDocument::query()
            ->where('status', FiscalDocumentStatus::Active)
            ->where('direction', FiscalDocumentDirection::Issued)
            ->where('currency', 'EUR')
            ->whereIn('type', [FiscalDocumentType::Receipt, FiscalDocumentType::InvoiceReceipt]);
    }

    private static function sourceHash(FiscalDocument $document, Payment $payment): string
    {
        return hash('sha256', json_encode([
            $document->getRawOriginal(),
            $document->links?->getRawOriginal(),
            $payment->id,
            $payment->paid_on->toDateString(),
            $payment->amount,
        ], JSON_THROW_ON_ERROR));
    }
}
