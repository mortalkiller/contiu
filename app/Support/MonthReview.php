<?php

namespace App\Support;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Models\BankTransaction;
use App\Models\CustomerRefund;
use App\Models\Expense;
use App\Models\FiscalDocument;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Receivable;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * "Fecho do mês": what falls due or was spent in a month, plus what is still open from earlier
 * months, and how much is left to deal with before the month can be considered closed.
 */
final class MonthReview
{
    public readonly CarbonImmutable $start;

    public readonly CarbonImmutable $end;

    private ?Reconciliation $reconciliation = null;

    public function __construct(CarbonInterface $month)
    {
        $this->start = CarbonImmutable::parse($month)->startOfMonth();
        $this->end = $this->start->endOfMonth();
    }

    /** @return Builder<Receivable> */
    public function receivablesQuery(bool $withFiscalReference = false): Builder
    {
        $issuedInvoice = fn (): Builder => $this->fiscalReferenceQuery(FiscalDocumentDirection::Issued, [
            FiscalDocumentType::Invoice, FiscalDocumentType::InvoiceReceipt, FiscalDocumentType::DebitNote,
        ])->whereHas('links', fn (Builder $links) => $links
            ->whereColumn('receivable_id', 'receivables.id')
            ->orWhere(fn (Builder $links) => $links
                ->where('fiscal_documents.type', FiscalDocumentType::InvoiceReceipt)
                ->whereExists(PaymentAllocation::query()
                    ->whereColumn('payment_id', 'fiscal_document_links.payment_id')
                    ->whereColumn('receivable_id', 'receivables.id'))));

        return Receivable::query()
            ->excludingCancelled()
            ->where(fn (Builder $query) => $query
                ->whereBetween('due_on', $this->range())
                ->orWhereExists($issuedInvoice()->whereBetween('document_date', $this->range()))
                ->orWhere(fn (Builder $query) => $query->whereDate('due_on', '<', $this->start)->open())
                // Due later, but part of it was paid this month (e.g. an advance on a January instalment).
                ->orWhere(fn (Builder $query) => $query
                    ->whereDate('due_on', '>', $this->end)
                    ->whereHas('payments', fn (Builder $query) => $query->whereBetween('paid_on', $this->range()))))
            ->with(['payments', 'billable.client'])
            ->when($withFiscalReference, fn (Builder $query) => $query->addSelect(['fiscal_reference' => $issuedInvoice()]))
            ->orderBy('due_on')
            ->orderBy('id');
    }

    /** @return Collection<int, Receivable> */
    public function receivables(): Collection
    {
        return $this->receivablesQuery()->get();
    }

    /** @return Builder<Expense> */
    public function expensesQuery(bool $withFiscalReference = false): Builder
    {
        return Expense::query()
            ->where(fn (Builder $query) => $query
                ->whereBetween('spent_on', $this->range())
                ->orWhere(fn (Builder $query) => $query->whereDate('spent_on', '<', $this->start)->where('is_paid', false)))
            ->with(['supplier', 'category', 'project', 'retainer'])
            ->when($withFiscalReference, fn (Builder $query) => $query->addSelect([
                'fiscal_reference' => $this->fiscalReferenceQuery(FiscalDocumentDirection::Received, [
                    FiscalDocumentType::Invoice, FiscalDocumentType::InvoiceReceipt,
                ])->whereHas('links', fn (Builder $links) => $links->whereColumn('expense_id', 'expenses.id')),
            ]))
            ->orderBy('spent_on')
            ->orderBy('id');
    }

    /** @return Collection<int, Expense> */
    public function expenses(): Collection
    {
        return $this->expensesQuery()->get();
    }

    /** @return Builder<Payment> */
    public function paymentsQuery(): Builder
    {
        $paymentDocument = $this->fiscalReferenceQuery(FiscalDocumentDirection::Issued, [
            FiscalDocumentType::Receipt, FiscalDocumentType::InvoiceReceipt,
        ]);
        $allocationDocument = (clone $paymentDocument)->whereHas('links', fn (Builder $links) => $links
            ->whereColumn('payment_id', 'payments.id')
            ->where(fn (Builder $coverage) => $coverage
                ->where(fn (Builder $whole) => $whole->whereNull('receivable_id')->whereNull('related_fiscal_document_id'))
                ->orWhere(fn (Builder $partial) => $partial
                    ->whereColumn('fiscal_documents.total_amount', 'payment_allocations.amount')
                    ->where(fn (Builder $target) => $target->whereColumn('receivable_id', 'payment_allocations.receivable_id')
                        ->orWhereHas('relatedFiscalDocument.links', fn (Builder $original) => $original
                            ->whereColumn('receivable_id', 'payment_allocations.receivable_id'))))));

        return Payment::query()
            ->whereBetween('paid_on', $this->range())
            ->with(['advanceClient', 'allocations.receivable.billable'])
            ->addSelect([
                'fiscal_reference' => $paymentDocument->whereHas('links', fn (Builder $links) => $links->whereColumn('payment_id', 'payments.id')),
                'uncovered_allocation' => PaymentAllocation::query()->select('id')->limit(1)
                    ->whereColumn('payment_id', 'payments.id')
                    ->whereNotExists($allocationDocument),
            ])
            ->orderBy('paid_on')
            ->orderBy('id');
    }

    /**
     * @param  list<FiscalDocumentType>  $types
     * @return Builder<FiscalDocument>
     */
    private function fiscalReferenceQuery(FiscalDocumentDirection $direction, array $types): Builder
    {
        return FiscalDocument::query()
            ->select('number')
            ->where('status', FiscalDocumentStatus::Active)
            ->where('direction', $direction)
            ->whereIn('type', $types)
            ->orderByDesc('document_date')
            ->orderByDesc('id')
            ->limit(1);
    }

    public static function missingDocumentLabel(Receivable|Payment|Expense $record): string
    {
        if ($record instanceof Payment && filled($record->fiscal_reference) && $record->uncovered_allocation !== null) {
            return __('Receipt missing for part of the payment');
        }

        return $record instanceof Payment ? __('Receipt or invoice-receipt missing') : __('Invoice or invoice-receipt missing');
    }

    public static function hasCompleteFiscalReference(Receivable|Payment|Expense $record): bool
    {
        return filled($record->fiscal_reference) && (! $record instanceof Payment || $record->uncovered_allocation === null);
    }

    public static function unconfirmedLegacyReference(Payment|Expense $record): ?string
    {
        return filled($record->invoice_number) && $record->invoice_number !== $record->fiscal_reference
            ? $record->invoice_number
            : null;
    }

    public function reconciliationFor(Payment|Expense $record): ?ReconciliationResult
    {
        if (! app(FinanceSettings::class)->hasBusinessAccount() || ($record instanceof Expense && ! $record->is_paid)) {
            return null;
        }

        $reconciliation = $this->reconciliation ??= new Reconciliation;

        return $record instanceof Payment ? $reconciliation->forPayment($record) : $reconciliation->forExpense($record);
    }

    /** @return list<string> */
    public function dataIssues(Receivable|Payment|Expense $record): array
    {
        $issues = [];

        if (! self::hasCompleteFiscalReference($record)) {
            $issues[] = self::missingDocumentLabel($record);
        }

        if ($record instanceof Expense && ! $record->hasProof()) {
            $issues[] = __('No supporting document');
        }

        if (! $record instanceof Receivable && $this->reconciliationFor($record)?->isReconciled() === false) {
            $issues[] = __('Pending reconciliation');
        }

        return $issues;
    }

    public function pendingDataCount(): int
    {
        return array_sum($this->pendingDataByKind());
    }

    /**
     * How many records of each kind have incomplete data, so "Fecho do mês" can take the user to
     * the first table with some.
     *
     * @return array{receivables: int, payments: int, expenses: int}
     */
    public function pendingDataByKind(): array
    {
        return [
            'receivables' => $this->receivablesQuery(withFiscalReference: true)->withoutEagerLoads()->get()->filter($this->dataIssues(...))->count(),
            'payments' => $this->paymentsQuery()->withoutEagerLoads()->get()->filter($this->dataIssues(...))->count(),
            'expenses' => $this->expensesQuery(withFiscalReference: true)->withoutEagerLoads()->get()->filter($this->dataIssues(...))->count(),
        ];
    }

    /** Whether the month is over: its end-of-month balances can differ from today's. */
    public function isPast(): bool
    {
        return $this->end->isBefore(today());
    }

    /** @return array{0: string, 1: string} SQLite data can include dates with or without a time part. */
    private function range(): array
    {
        return [$this->start->toDateString(), $this->end->toDateTimeString()];
    }

    public function isCarriedOver(Receivable|Expense $item): bool
    {
        $date = $item instanceof Receivable ? $item->due_on : $item->spent_on;

        return $date->lt($this->start);
    }

    /** Listed only because it received a payment this month; it is not due yet. */
    public function isDueLater(Receivable $receivable): bool
    {
        return $receivable->due_on->gt($this->end);
    }

    public function outstandingAtClose(Receivable $receivable): int
    {
        return $receivable->outstandingAt($this->end);
    }

    public function received(): int
    {
        return (int) Payment::query()->whereBetween('paid_on', $this->range())->sum('amount');
    }

    public function paidExpenses(): int
    {
        return (int) Expense::query()
            ->where('is_paid', true)
            ->whereBetween('spent_on', $this->range())
            ->get()
            ->sum(fn (Expense $expense) => $expense->total);
    }

    public function result(): int
    {
        return $this->received() - $this->paidExpenses() - $this->refunded();
    }

    public function refunded(): int
    {
        return (int) CustomerRefund::query()->whereBetween('paid_on', $this->range())->sum('amount');
    }

    public function expected(): int
    {
        return (int) $this->receivables()
            ->filter(fn (Receivable $receivable) => ! $this->isCarriedOver($receivable) && ! $this->isDueLater($receivable))
            ->sum('amount');
    }

    public function pendingReceivables(): int
    {
        return $this->receivables()
            ->filter(fn (Receivable $receivable) => $receivable->outstanding > 0 && ! $this->isDueLater($receivable))
            ->count();
    }

    public function pendingExpenses(): int
    {
        return $this->expenses()->where('is_paid', false)->count();
    }

    /** Statement lines of the month not treated yet; none without a bank account for the activity. */
    public function pendingBankLines(): int
    {
        return $this->pendingBankLinesQuery()->count();
    }

    /** @return Builder<BankTransaction> */
    public function pendingBankLinesQuery(): Builder
    {
        if (! app(FinanceSettings::class)->hasBusinessAccount()) {
            return BankTransaction::query()->whereRaw('1 = 0');
        }

        return BankTransaction::query()->pending()->whereBetween('booked_on', $this->range());
    }

    public function pendingCount(): int
    {
        return $this->pendingReceivables() + $this->pendingExpenses() + $this->pendingBankLines();
    }

    public function isClosed(): bool
    {
        return $this->pendingCount() === 0;
    }
}
