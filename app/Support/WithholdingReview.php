<?php

namespace App\Support;

use App\Enums\BankTransactionStatus;
use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Exceptions\AccountIsReadOnly;
use App\Models\Account;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLink;
use App\Models\InvoiceXpressReceiptAttempt;
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

/** Documentary IRS withholding reported by payment date in integer EUR cents. */
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
        $signalRecords = $rows->filter(fn (WithholdingRecord $record): bool => $record->isAdvanceEvidence())->modelKeys();
        $signals = $signalRecords === [] ? [] : self::advanceEvidence()->whereIntegerInRaw('withholding_records.id', $signalRecords)
            ->pluck('withholding_records.id')->flip()->all();

        $rows = $rows->map(function (WithholdingRecord $record) use ($confirmed, $signals): array {
            $payment = $record->payment;
            $document = $record->document;
            $current = match (true) {
                $record->payment_allocation_id !== null => isset($confirmed[$record->id]),
                $record->isAdvanceEvidence() => isset($signals[$record->id]),
                default => $document->links?->payment_id == $payment->id && $record->source_hash === self::sourceHash($document, $payment),
            };

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
        if ($retained <= 0) {
            throw self::invalid('retained', __('Enter a positive amount withheld.'));
        }

        self::recordDocument($documentId, $retained, $year, $allocationId);
    }

    /** Reconcile an issued receipt against an existing cash allocation. Amounts are EUR cents. */
    public static function reconcileReceipt(int $documentId, int $allocationId, int $retained = 0): void
    {
        self::recordDocument($documentId, $retained, null, $allocationId);
    }

    private static function recordDocument(int $documentId, int $retained, ?int $year, ?int $allocationId): void
    {
        if (CurrentAccount::readOnly()) {
            throw new AccountIsReadOnly;
        }

        DB::transaction(function () use ($documentId, $retained, $year, $allocationId): void {
            Account::query()->lockForUpdate()->findOrFail(CurrentAccount::id());
            $document = self::sourceDocuments()->lockForUpdate()->find($documentId);
            if ($document === null) {
                throw self::invalid('documentId', __('Choose an issued, active receipt or invoice-receipt in EUR.'));
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
                throw self::invalid('documentId', __('Choose a receipt linked to a payment from this year.'));
            }

            if ($payment->isAdvance()) {
                self::recordAdvance($document, $payment, $retained, $record);

                return;
            }

            if ($payment->is_split) {
                throw self::invalid('documentId', __('Choose the allocation for this receipt before recording the withholding tax.'));
            }

            $allocation = $payment->allocations()->first();
            if ($allocation !== null && auth()->check() && self::original($allocation) !== null) {
                self::reconcileAllocation($document, $retained, $year, $allocation->id, $record);

                return;
            }

            self::validateCash($document, $retained, $payment->amount);

            if (WithholdingRecord::query()->where('payment_id', $payment->id)->where('fiscal_document_id', '!=', $documentId)->exists()) {
                throw self::invalid('documentId', __('Withholding tax has already been recorded for this payment.'));
            }

            WithholdingRecord::query()->updateOrCreate(['fiscal_document_id' => $documentId], self::recordData($document, $payment, $retained));
        }, attempts: 3);
    }

    /** Documented retention for the original signal invoice, even before cash is allocated. */
    public static function advanceRetention(Payment $payment, FiscalDocument $document): int
    {
        return self::confirmedAdvanceEvidence($payment, $document)?->retained_amount ?? 0;
    }

    public static function confirmedAdvanceEvidence(Payment $payment, FiscalDocument $document): ?WithholdingRecord
    {
        $accountId = CurrentAccount::get()?->id;
        if ($accountId === null || (int) $payment->account_id !== $accountId || (int) $document->account_id !== $accountId) {
            return null;
        }

        $evidence = self::advanceEvidence()->where('withholding_records.payment_id', $payment->id)
            ->where('withholding_records.original_document_id', $document->id)
            ->first(['withholding_records.*']);

        return $evidence === null ? null : (new WithholdingRecord)->newFromBuilder((array) $evidence);
    }

    private static function recordAdvance(FiscalDocument $document, Payment $payment, int $retained, ?WithholdingRecord $record): void
    {
        abort_unless(Gate::allows('update', $document) && Gate::allows('view', $payment), 403);
        $invoice = $document->type === FiscalDocumentType::InvoiceReceipt ? $document : $document->links?->relatedFiscalDocument;
        $allocations = $payment->allocations()->lockForUpdate()->get();
        $allocation = $allocations->first();
        $charge = $allocation?->receivable()->excludingCancelled()->lockForUpdate()->first();
        $nif = InvoiceXpressIssueTerms::nif($payment->advanceClient?->vat_number);

        self::validateCash($document, $retained, $payment->amount);
        if ($invoice === null || $invoice->direction !== FiscalDocumentDirection::Issued
            || $invoice->status !== FiscalDocumentStatus::Active || $invoice->currency !== 'EUR'
            || ($invoice->isNot($document) && $invoice->type !== FiscalDocumentType::Invoice)
            || $invoice->links?->payment_id !== $payment->id || $invoice->links?->expense_id !== null
            || $document->links?->expense_id !== null || ($invoice->is($document) && $document->links?->related_fiscal_document_id !== null)
            || ! $invoice->document_date->isSameDay($payment->paid_on) || $document->document_date->lt($invoice->document_date)
            || $invoice->total_amount !== $document->total_amount || $invoice->net_amount + $invoice->vat_amount !== $invoice->total_amount
            || ($nif !== null && InvoiceXpressIssueTerms::nif($invoice->party_tax_id) !== $nif)
            || ($document->party_tax_id !== null && InvoiceXpressIssueTerms::nif($document->party_tax_id) !== InvoiceXpressIssueTerms::nif($invoice->party_tax_id))
            || $allocations->count() > 1 || ($allocation !== null && ($charge === null || $allocation->amount !== $payment->amount
                || (int) $charge->client?->id !== (int) $payment->advance_client_id))
            || ($document->links?->receivable_id !== null && $document->links->receivable_id !== $charge?->id)
            || ($invoice->links?->receivable_id !== null && $invoice->links->receivable_id !== $charge?->id)
            || ($record !== null && $record->payment_id !== $payment->id)
            || WithholdingRecord::query()->where('payment_id', $payment->id)->where('fiscal_document_id', '!=', $document->id)->exists()) {
            throw self::invalid('documentId', __('Check the document, client, and advance payment. You can record withholding tax only once per advance.'));
        }

        if ($charge !== null) {
            self::checkBalance($charge, $allocation->allocated_on->toDateString(), $retained, $document->id);
        }

        WithholdingRecord::query()->updateOrCreate(['fiscal_document_id' => $document->id], [
            ...self::recordData($document, $payment, $retained),
            ...self::documentEvidence($document),
            'original_document_id' => $invoice->id,
        ]);
    }

    private static function advanceEvidence(): QueryBuilder
    {
        return DB::table('withholding_records')
            ->join('payments as signal_cash', fn (JoinClause $join) => $join
                ->on('signal_cash.account_id', '=', 'withholding_records.account_id')
                ->on('signal_cash.id', '=', 'withholding_records.payment_id'))
            ->join('fiscal_documents as signal_receipt', fn (JoinClause $join) => $join
                ->on('signal_receipt.account_id', '=', 'withholding_records.account_id')
                ->on('signal_receipt.id', '=', 'withholding_records.fiscal_document_id'))
            ->join('fiscal_documents as signal_invoice', fn (JoinClause $join) => $join
                ->on('signal_invoice.account_id', '=', 'withholding_records.account_id')
                ->on('signal_invoice.id', '=', 'withholding_records.original_document_id'))
            ->join('fiscal_document_links as signal_receipt_link', fn (JoinClause $join) => $join
                ->on('signal_receipt_link.account_id', '=', 'signal_receipt.account_id')
                ->on('signal_receipt_link.fiscal_document_id', '=', 'signal_receipt.id')
                ->on('signal_receipt_link.payment_id', '=', 'signal_cash.id'))
            ->join('fiscal_document_links as signal_invoice_link', fn (JoinClause $join) => $join
                ->on('signal_invoice_link.account_id', '=', 'signal_invoice.account_id')
                ->on('signal_invoice_link.fiscal_document_id', '=', 'signal_invoice.id')
                ->on('signal_invoice_link.payment_id', '=', 'signal_cash.id'))
            ->where('withholding_records.account_id', CurrentAccount::id())
            ->whereNull('withholding_records.payment_allocation_id')
            ->where('withholding_records.retained_amount', '>', 0)
            ->whereNotNull('signal_cash.advance_client_id')
            ->whereNull('signal_receipt_link.expense_id')->whereNull('signal_invoice_link.expense_id')
            ->where('signal_receipt.status', FiscalDocumentStatus::Active->value)
            ->where('signal_invoice.status', FiscalDocumentStatus::Active->value)
            ->where('signal_receipt.direction', FiscalDocumentDirection::Issued->value)
            ->where('signal_invoice.direction', FiscalDocumentDirection::Issued->value)
            ->where('signal_receipt.currency', 'EUR')->where('signal_invoice.currency', 'EUR')
            ->whereRaw('date(signal_cash.paid_on) = date(withholding_records.paid_on)')
            ->whereRaw('date(signal_invoice.document_date) = date(signal_cash.paid_on)')
            ->whereRaw('date(signal_receipt.document_date) = date(withholding_records.document_date)')
            ->whereColumn('signal_receipt.number', 'withholding_records.document_number')
            ->whereColumn('signal_receipt.net_amount', 'withholding_records.document_net_amount')
            ->whereColumn('signal_receipt.total_amount', 'withholding_records.gross_amount')
            ->whereColumn('signal_invoice.total_amount', 'withholding_records.gross_amount')
            ->whereRaw('signal_invoice.net_amount + signal_invoice.vat_amount = signal_invoice.total_amount')
            ->whereRaw('signal_receipt.net_amount + signal_receipt.vat_amount = signal_receipt.total_amount')
            ->whereRaw('signal_cash.amount + withholding_records.retained_amount = withholding_records.gross_amount')
            ->where(fn (QueryBuilder $query) => $query
                ->where(fn (QueryBuilder $query) => $query->where('signal_receipt.type', FiscalDocumentType::InvoiceReceipt->value)
                    ->whereColumn('signal_receipt.id', 'signal_invoice.id')->whereNull('signal_receipt_link.related_fiscal_document_id'))
                ->orWhere(fn (QueryBuilder $query) => $query->where('signal_receipt.type', FiscalDocumentType::Receipt->value)
                    ->where('signal_invoice.type', FiscalDocumentType::Invoice->value)
                    ->whereColumn('signal_receipt_link.related_fiscal_document_id', 'signal_invoice.id')));
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
                    ->whereColumn('receipt.id', 'original.id')))
            ->where(fn (QueryBuilder $query) => $query
                ->where(fn (QueryBuilder $query) => $query->where('withholding_records.settles_receivable', true)
                    ->where(fn (QueryBuilder $query) => $query
                        ->where(fn (QueryBuilder $query) => $query->whereNull('original_link.related_fiscal_document_id')
                            ->whereRaw('original.net_amount + original.vat_amount = charge.amount'))
                        ->orWhereExists(self::appliedAdvanceDocuments()
                            ->whereColumn('advance_document.id', 'original_link.related_fiscal_document_id')
                            ->whereColumn('advance_allocation.receivable_id', 'charge.id')
                            ->whereRaw('date(advance_allocation.allocated_on) <= date(original.document_date)')
                            ->whereRaw('original.net_amount + original.vat_amount = original.total_amount')
                            ->whereRaw('original.total_amount + advance_document.total_amount = charge.amount'))))
                ->orWhere(fn (QueryBuilder $query) => $query->where('withholding_records.settles_receivable', false)
                    ->whereNull('original_link.related_fiscal_document_id')
                    ->whereColumn('charge.amount', 'allocation.amount')
                    ->whereRaw('original.net_amount + original.vat_amount = receipt.total_amount')));
    }

    public static function settlementsOn(string $day): QueryBuilder
    {
        $ordinary = self::confirmedAllocations()
            ->where('withholding_records.settles_receivable', true)
            ->whereDate('withholding_records.paid_on', '<=', $day)
            ->select('withholding_records.id', 'withholding_records.account_id', 'withholding_records.receivable_id',
                'withholding_records.fiscal_document_id', 'withholding_records.retained_amount', 'withholding_records.document_number', 'withholding_records.paid_on');
        $signals = self::advanceEvidence()
            ->join('payment_allocations as signal_allocation', fn (JoinClause $join) => $join
                ->on('signal_allocation.account_id', '=', 'signal_cash.account_id')
                ->on('signal_allocation.payment_id', '=', 'signal_cash.id'))
            ->whereColumn('signal_allocation.amount', 'signal_cash.amount')
            ->whereRaw('date(signal_allocation.allocated_on) >= date(signal_cash.paid_on)')
            ->whereDate('signal_allocation.allocated_on', '<=', $day)
            ->where(fn (QueryBuilder $query) => $query->whereNull('signal_receipt_link.receivable_id')
                ->orWhereColumn('signal_receipt_link.receivable_id', 'signal_allocation.receivable_id'))
            ->where(fn (QueryBuilder $query) => $query->whereNull('signal_invoice_link.receivable_id')
                ->orWhereColumn('signal_invoice_link.receivable_id', 'signal_allocation.receivable_id'))
            ->whereNotExists(self::otherAllocations('signal_allocation', 'signal_cash'))
            ->select('withholding_records.id', 'withholding_records.account_id', 'signal_allocation.receivable_id',
                'withholding_records.fiscal_document_id', 'withholding_records.retained_amount', 'withholding_records.document_number',
                'signal_allocation.allocated_on as paid_on');

        return DB::query()->fromSub($ordinary->unionAll($signals), 'withholding_records');
    }

    private static function appliedAdvanceDocuments(): QueryBuilder
    {
        $evidence = self::advanceEvidence()->select('withholding_records.payment_id', 'withholding_records.original_document_id', 'withholding_records.retained_amount');

        return DB::table('fiscal_documents as advance_document')
            ->join('fiscal_document_links as advance_link', fn (JoinClause $join) => $join
                ->on('advance_link.account_id', '=', 'advance_document.account_id')
                ->on('advance_link.fiscal_document_id', '=', 'advance_document.id'))
            ->join('payments as advance_cash', fn (JoinClause $join) => $join
                ->on('advance_cash.account_id', '=', 'advance_link.account_id')
                ->on('advance_cash.id', '=', 'advance_link.payment_id'))
            ->join('payment_allocations as advance_allocation', fn (JoinClause $join) => $join
                ->on('advance_allocation.account_id', '=', 'advance_cash.account_id')
                ->on('advance_allocation.payment_id', '=', 'advance_cash.id'))
            ->leftJoinSub($evidence, 'advance_retention', fn (JoinClause $join) => $join
                ->on('advance_retention.payment_id', '=', 'advance_cash.id')
                ->on('advance_retention.original_document_id', '=', 'advance_document.id'))
            ->where('advance_document.account_id', CurrentAccount::id())
            ->where('advance_document.status', FiscalDocumentStatus::Active->value)
            ->where('advance_document.direction', FiscalDocumentDirection::Issued->value)
            ->where('advance_document.currency', 'EUR')
            ->whereIn('advance_document.type', [FiscalDocumentType::Invoice->value, FiscalDocumentType::InvoiceReceipt->value])
            ->whereNull('advance_link.expense_id')
            ->whereNotNull('advance_cash.advance_client_id')
            ->whereColumn('advance_cash.amount', 'advance_allocation.amount')
            ->whereRaw('date(advance_document.document_date) = date(advance_cash.paid_on)')
            ->whereRaw('date(advance_allocation.allocated_on) >= date(advance_cash.paid_on)')
            ->whereRaw('advance_document.net_amount + advance_document.vat_amount = advance_document.total_amount')
            ->whereRaw('advance_cash.amount + coalesce(advance_retention.retained_amount, 0) = advance_document.total_amount')
            ->where(fn (QueryBuilder $query) => $query->whereNull('advance_link.receivable_id')
                ->orWhereColumn('advance_link.receivable_id', 'advance_allocation.receivable_id'))
            ->whereNotExists(self::otherAllocations('advance_allocation', 'advance_cash'));
    }

    private static function otherAllocations(string $allocationAlias, string $cashAlias): QueryBuilder
    {
        return DB::table('payment_allocations as other_allocation')
            ->whereColumn('other_allocation.account_id', $cashAlias.'.account_id')
            ->whereColumn('other_allocation.payment_id', $cashAlias.'.id')
            ->whereColumn('other_allocation.id', '!=', $allocationAlias.'.id');
    }

    /** @return Collection<int, FiscalDocument> */
    public static function sourcesForAllocation(PaymentAllocation $allocation, bool $ordinary = false): Collection
    {
        $invoice = self::original($allocation);
        if ($invoice === null) {
            return collect();
        }

        return self::sourceDocuments()
            ->where(fn (Builder $query) => $query->where('type', FiscalDocumentType::Receipt)->orWhereKey($invoice->id))
            ->when($ordinary, fn (Builder $query) => $query->where('total_amount', $allocation->amount),
                fn (Builder $query) => $query->whereBetween('total_amount', [$allocation->amount + 1, $invoice->net_amount + $invoice->vat_amount]))
            ->where('document_date', '>=', $invoice->document_date->toDateString())
            ->with('links')
            ->orderByDesc('document_date')->orderByDesc('id')->get()
            ->filter(fn (FiscalDocument $document): bool => self::compatibleLink($document, $allocation, $invoice));
    }

    /** @return array{invoice: string, cash: int, availableGross: int} */
    public static function previewAllocation(PaymentAllocation $allocation): array
    {
        $invoice = self::original($allocation);
        $charge = $allocation->receivable;
        $payment = $allocation->payment;
        if ($invoice === null || $charge === null || $payment === null || $payment->isAdvance()
            || $invoice->document_date->isAfter($payment->paid_on)) {
            throw self::invalid('documentId', __('The allocation requires an active invoice linked to the charge.'));
        }

        [$gross] = self::invoiceBalance($invoice, $charge);
        $documentId = FiscalDocumentLink::query()->where('receivable_id', $charge->id)->where('payment_id', $payment->id)
            ->whereHas('fiscalDocument', fn (Builder $query) => $query->where('type', FiscalDocumentType::Receipt)
                ->where('status', FiscalDocumentStatus::Active))->value('fiscal_document_id');

        return [
            'invoice' => $invoice->number,
            'cash' => $allocation->amount,
            'availableGross' => self::availableGross($allocation, $charge, $gross, $documentId ?? $invoice->id),
        ];
    }

    private static function reconcileAllocation(FiscalDocument $document, int $retained, ?int $year, int $allocationId, ?WithholdingRecord $record): void
    {
        $allocation = PaymentAllocation::query()->find($allocationId);
        $receivable = $allocation?->receivable()->excludingCancelled()->lockForUpdate()->first();
        $payment = $allocation?->payment()->lockForUpdate()->first();
        $allocation = $payment?->allocations()->where('receivable_id', $receivable?->id)->lockForUpdate()->find($allocationId);
        if ($allocation === null || $receivable === null || $payment->isAdvance() || ! $allocation->allocated_on->isSameDay($payment->paid_on)
            || ($year !== null && $payment->paid_on->year !== $year)) {
            throw self::invalid('documentId', __('The allocation must correspond to a payment from this year, applied on the date it was received.'));
        }

        abort_unless(Gate::allows('update', $document) && Gate::allows('view', $payment), 403);
        $invoice = self::original($allocation, lock: true);
        if ($invoice === null || $invoice->document_date->isAfter($payment->paid_on) || $document->document_date->lt($invoice->document_date)
            || ! self::compatibleLink($document, $allocation, $invoice)) {
            throw self::invalid('documentId', __('Check the receipt and its link to the original invoice for this charge.'));
        }

        self::validateCash($document, $retained, $allocation->amount);
        if (($record?->payment_allocation_id !== null && $record->payment_allocation_id !== $allocation->id)
            || ($record !== null && ($record->payment_id !== $payment->id || $retained === 0))
            || WithholdingRecord::query()->where('fiscal_document_id', '!=', $document->id)
                ->where(fn (Builder $query) => $query->where('payment_allocation_id', $allocation->id)
                    ->orWhere(fn (Builder $query) => $query->where('payment_id', $payment->id)->whereNull('payment_allocation_id')))
                ->exists()
            || FiscalDocumentLink::query()->where('fiscal_document_id', '!=', $document->id)
                ->where('payment_id', $payment->id)
                ->where(fn (Builder $query) => $query->where('receivable_id', $receivable->id)
                    ->orWhere('related_fiscal_document_id', $invoice->id)
                    ->when(! $payment->is_split, fn (Builder $query) => $query
                        ->orWhere(fn (Builder $query) => $query->whereNull('receivable_id')->whereNull('related_fiscal_document_id'))))
                ->whereHas('fiscalDocument', fn (Builder $query) => $query->where('status', FiscalDocumentStatus::Active)
                    ->whereIn('type', [FiscalDocumentType::Receipt, FiscalDocumentType::InvoiceReceipt]))
                ->exists()
            || InvoiceXpressReceiptAttempt::query()->where('payment_id', $payment->id)->where('receivable_id', $receivable->id)
                ->where(fn (Builder $query) => $query->whereNull('fiscal_document_id')->orWhere('fiscal_document_id', '!=', $document->id))
                ->exists()) {
            throw self::invalid('documentId', __('A receipt, withholding record, or issuance attempt already exists for this allocation.'));
        }

        [$invoiceGross, $settles] = self::invoiceBalance($invoice, $receivable);
        if ($document->total_amount > $invoiceGross || (! $settles
            && ($receivable->amount !== $allocation->amount || $document->total_amount !== $invoiceGross))) {
            throw self::invalid('documentId', __('The gross invoice amount must match the charge, or the receipt must show full settlement of a charge already recorded at the net amount.'));
        }

        if ($settles) {
            if ($document->total_amount > self::availableGross($allocation, $receivable, $invoiceGross, $document->id)) {
                throw self::invalid('documentId', __('The gross amount of this receipt exceeds the available invoice balance.'));
            }

            self::checkBalance($receivable, $payment->paid_on->toDateString(), $retained, $document->id);
        }

        $document->links()->updateOrCreate([], [
            'payment_id' => $payment->id,
            'receivable_id' => $receivable->id,
            'related_fiscal_document_id' => $document->id === $invoice->id ? $invoice->links?->related_fiscal_document_id : $invoice->id,
        ]);
        $document->unsetRelation('links');

        if ($retained === 0) {
            return;
        }

        WithholdingRecord::query()->updateOrCreate(['fiscal_document_id' => $document->id], [
            ...self::recordData($document, $payment, $retained),
            ...self::documentEvidence($document),
            'payment_allocation_id' => $allocation->id,
            'receivable_id' => $receivable->id,
            'original_document_id' => $invoice->id,
            'receivable_amount' => $receivable->amount,
            'settles_receivable' => $settles,
        ]);
    }

    /** @return array{int, bool} */
    private static function invoiceBalance(FiscalDocument $invoice, Receivable $receivable): array
    {
        $gross = $invoice->net_amount + $invoice->vat_amount;
        $advanceId = $invoice->links?->related_fiscal_document_id;
        if ($advanceId === null) {
            return [$gross, $gross === $receivable->amount];
        }

        $advanceGross = self::appliedAdvanceDocuments()->where('advance_document.id', $advanceId)
            ->where('advance_allocation.receivable_id', $receivable->id)
            ->whereDate('advance_allocation.allocated_on', '<=', $invoice->document_date->toDateString())
            ->value('advance_document.total_amount');

        if ($advanceGross === null || $gross !== $invoice->total_amount || $gross + (int) $advanceGross !== $receivable->amount) {
            throw self::invalid('documentId', __('Check the link to the advance and the gross balance of the final invoice.'));
        }

        return [$gross, true];
    }

    private static function availableGross(PaymentAllocation $allocation, Receivable $charge, int $invoiceGross, int $documentId): int
    {
        $day = max($allocation->allocated_on->toDateString(), today()->toDateString());
        $otherCash = PaymentAllocation::query()->where('receivable_id', $charge->id)->whereKeyNot($allocation->id)
            ->whereHas('payment', fn (Builder $query) => $query->whereNull('advance_client_id'))
            ->whereDate('allocated_on', '<=', $day)->sum('amount');
        $credits = $charge->creditApplications()->effectiveAt($day)->sum('amount');
        $otherRetention = self::confirmedAllocations()->where('withholding_records.receivable_id', $charge->id)
            ->where('withholding_records.settles_receivable', true)
            ->where('withholding_records.fiscal_document_id', '!=', $documentId)
            ->whereDate('withholding_records.paid_on', '<=', $day)->sum('withholding_records.retained_amount');

        return max(0, $invoiceGross - $otherCash - $credits - $otherRetention);
    }

    /** @return array{document_net_amount: int, document_date: string, document_number: string} */
    private static function documentEvidence(FiscalDocument $document): array
    {
        return [
            'document_net_amount' => $document->net_amount,
            'document_date' => $document->document_date->toDateString(),
            'document_number' => $document->number,
        ];
    }

    private static function checkBalance(Receivable $receivable, string $paidOn, int $retained, int $documentId): void
    {
        foreach (array_unique([$paidOn, max($paidOn, today()->toDateString())]) as $day) {
            $cash = PaymentAllocation::query()->where('receivable_id', $receivable->id)->whereDate('allocated_on', '<=', $day)->sum('amount');
            $credits = $receivable->creditApplications()->effectiveAt($day)->sum('amount');
            $otherRetention = self::settlementsOn($day)->where('withholding_records.receivable_id', $receivable->id)
                ->where('withholding_records.fiscal_document_id', '!=', $documentId)->sum('withholding_records.retained_amount');

            if ($retained > $receivable->amount - $cash - $credits - $otherRetention) {
                throw self::invalid('retained', __('The amount withheld exceeds the outstanding balance of this charge.'));
            }
        }
    }

    private static function original(PaymentAllocation $allocation, bool $lock = false): ?FiscalDocument
    {
        $documents = FiscalDocument::query()->where('direction', FiscalDocumentDirection::Issued)
            ->where('status', FiscalDocumentStatus::Active)->where('currency', 'EUR')
            ->whereIn('type', [FiscalDocumentType::Invoice, FiscalDocumentType::InvoiceReceipt])
            ->whereHas('links', fn (Builder $query) => $query->where('receivable_id', $allocation->receivable_id))
            ->whereDoesntHave('links.payment', fn (Builder $query) => $query->whereNotNull('advance_client_id'))
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
            && ($document->is($invoice) || $link?->related_fiscal_document_id === null || (int) $link->related_fiscal_document_id === $invoice->id)
            && $link?->expense_id === null
            && ($receiptParty === '' || $invoiceParty === '' || $receiptParty === $invoiceParty);
    }

    private static function validateAmounts(FiscalDocument $document, int $retained): void
    {
        if ($document->net_amount <= 0 || $document->vat_amount < 0 || $document->total_amount - $document->net_amount !== $document->vat_amount) {
            throw self::invalid('documentId', __('Check the amount before VAT, the VAT, and the total before withholding on the document.'));
        }

        if ($retained < 0 || $retained > $document->net_amount) {
            throw self::invalid('retained', __('The amount withheld cannot be negative or exceed the document amount before VAT.'));
        }
    }

    private static function validateCash(FiscalDocument $document, int $retained, int $cash): void
    {
        if ($cash <= 0 || $document->total_amount - $retained !== $cash) {
            throw self::invalid('retained', __('The total before withholding, less the amount withheld, must match the payment received to the cent.'));
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
