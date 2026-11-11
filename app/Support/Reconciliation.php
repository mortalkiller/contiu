<?php

namespace App\Support;

use App\Enums\Language;
use App\Enums\ReconciliationStatus;
use App\Models\Expense;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Reconciled or not, for every payment and paid expense, from the bank statement:
 * - linked to a statement line → reconciled by that line;
 * - otherwise covered by "Transferência minha" lines (TransferCoverage): going through the
 *   transfers by date, money that came in covers the oldest records first. What no transfer
 *   covered yet is "por reconciliar" (the last one maybe in part).
 * Once every statement line is treated, what is "por reconciliar" adds up to the company account
 * card's "falta transferir". Nothing about other accounts is stored or assumed.
 */
final class Reconciliation
{
    /** @var array<int, ReconciliationResult>|null */
    private ?array $payments = null;

    /** @var array<int, ReconciliationResult>|null */
    private ?array $expenses = null;

    private readonly CompanyAccount $account;

    private readonly TransferCoverage $coverage;

    public function __construct(?CompanyAccount $account = null)
    {
        $this->account = $account ?? new CompanyAccount;
        $this->coverage = new TransferCoverage;
    }

    public function forPayment(Payment $payment): ReconciliationResult
    {
        return $this->paymentResults()[$payment->getKey()] ?? $this->noStatement((int) $payment->amount);
    }

    public function forExpense(Expense $expense): ReconciliationResult
    {
        return $this->expenseResults()[$expense->getKey()] ?? $this->noStatement($expense->total);
    }

    /** Cents of payments not matched by the bank yet. */
    public function missingPayments(): int
    {
        return (int) collect($this->paymentResults())->sum(fn (ReconciliationResult $result) => $result->missing);
    }

    /** @return array<int, int> ids of payments with this status */
    public function paymentIds(ReconciliationStatus $status): array
    {
        return array_keys(array_filter($this->paymentResults(), fn (ReconciliationResult $result) => $result->status === $status));
    }

    /** @return array<int, int> ids of paid expenses with this status */
    public function expenseIds(ReconciliationStatus $status): array
    {
        return array_keys(array_filter($this->expenseResults(), fn (ReconciliationResult $result) => $result->status === $status));
    }

    /** @return array<int, ReconciliationResult> */
    private function paymentResults(): array
    {
        return $this->payments ??= $this->classify(
            Payment::query()->with('bankLink.bankTransaction')->get(),
            fn (Payment $payment) => $payment->paid_on,
            fn (Payment $payment) => (int) $payment->amount,
        );
    }

    /** @return array<int, ReconciliationResult> */
    private function expenseResults(): array
    {
        return $this->expenses ??= $this->classify(
            Expense::query()->where('is_paid', true)->with('bankLink.bankTransaction')->get(),
            fn (Expense $expense) => $expense->paid_on ?? $expense->spent_on,
            fn (Expense $expense) => $expense->total,
        );
    }

    /**
     * @param  Collection<int, Payment|Expense>  $records
     * @return array<int, ReconciliationResult>
     */
    private function classify(Collection $records, callable $date, callable $amount): array
    {
        $end = $this->account->endsOn();
        $start = $this->account->startsOn();
        $countsFromStart = $this->account->openingBalance() !== 0; // opening 0: everything before counts too

        return $records->mapWithKeys(function ($record) use ($date, $amount, $end, $start, $countsFromStart): array {
            $day = $date($record) === null ? null : Carbon::parse($date($record))->startOfDay();
            $total = $amount($record);
            $covered = array_map(fn (array $by) => [...$by, 'direct' => false], $this->coverage->coveredBy($record));

            $result = match (true) {
                $end === null => $this->noStatement($total),
                $record->bankLink?->bankTransaction !== null => new ReconciliationResult(ReconciliationStatus::Reconciled, $total, lines: [['line' => $record->bankLink->bankTransaction, 'amount' => $total, 'direct' => true]]),
                $day === null || $day->gt($end) => new ReconciliationResult(ReconciliationStatus::NotReconciled, $total, $total, __('After the last statement (:date)', ['date' => $end->format(Language::resolve(app()->getLocale())->dateFormat())])),
                $countsFromStart && $start !== null && $day->lt($start) => new ReconciliationResult(ReconciliationStatus::Reconciled, $total, note: __('Before the first statement')),
                default => (function () use ($record, $total, $covered): ReconciliationResult {
                    $missing = $this->coverage->remaining($record) ?? $total;

                    return new ReconciliationResult($missing > 0 ? ReconciliationStatus::NotReconciled : ReconciliationStatus::Reconciled, $total, $missing, lines: $covered);
                })(),
            };

            return [$record->getKey() => $result];
        })->all();
    }

    private function noStatement(int $amount): ReconciliationResult
    {
        return new ReconciliationResult(ReconciliationStatus::NotReconciled, $amount, $amount, __('No statement imported'));
    }
}
