<?php

namespace App\Support;

use App\Enums\ReconciliationStatus;
use App\Models\Expense;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Reconciled or not, for every payment and paid expense, from the bank statement:
 * - linked to a statement line → reconciled;
 * - otherwise it is covered by the balance: the company account card (CompanyAccount) knows how
 *   much is still missing in the bank. Taking the oldest records as covered first, the newest
 *   ones that add up to that amount are "por reconciliar" (the last one maybe in part) and the
 *   older ones are reconciled.
 * So what is "por reconciliar" always adds up to the card's "falta transferir". Nothing about
 * other accounts is stored or assumed: only the bank statement and the records.
 */
final class Reconciliation
{
    /** @var array<int, ReconciliationResult>|null */
    private ?array $payments = null;

    /** @var array<int, ReconciliationResult>|null */
    private ?array $expenses = null;

    private readonly CompanyAccount $account;

    public function __construct(?CompanyAccount $account = null)
    {
        $this->account = $account ?? new CompanyAccount;
    }

    public function forPayment(Payment $payment): ReconciliationResult
    {
        return $this->paymentResults()[$payment->getKey()] ?? $this->noStatement((int) $payment->amount);
    }

    public function forExpense(Expense $expense): ReconciliationResult
    {
        return $this->expenseResults()[$expense->getKey()] ?? $this->noStatement($expense->total);
    }

    /** Cents of payments not matched by the bank yet (= the card's "falta transferir"). */
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
        $difference = $this->account->difference();

        return $this->payments ??= $this->classify(
            Payment::query()->with('bankLink')->get(),
            fn (Payment $payment) => $payment->paid_on,
            fn (Payment $payment) => (int) $payment->amount,
            $difference === null ? 0 : max(0, -$difference),
        );
    }

    /** @return array<int, ReconciliationResult> */
    private function expenseResults(): array
    {
        $difference = $this->account->difference();

        return $this->expenses ??= $this->classify(
            Expense::query()->where('is_paid', true)->with('bankLink')->get(),
            fn (Expense $expense) => $expense->paid_on ?? $expense->spent_on,
            fn (Expense $expense) => $expense->total,
            $difference === null ? 0 : max(0, $difference),
        );
    }

    /**
     * @param  Collection<int, Payment|Expense>  $records
     * @return array<int, ReconciliationResult>
     */
    private function classify(Collection $records, callable $date, callable $amount, int $missing): array
    {
        $end = $this->account->endsOn();
        $start = $this->account->startsOn();
        $countsFromStart = $this->account->openingBalance() !== 0; // opening 0: everything before counts too
        $results = [];

        $candidates = $records->filter(function ($record) use (&$results, $date, $amount, $end, $start, $countsFromStart): bool {
            $day = $date($record) === null ? null : Carbon::parse($date($record))->startOfDay();

            $result = match (true) {
                $end === null => $this->noStatement($amount($record)),
                $record->bankLink !== null => new ReconciliationResult(ReconciliationStatus::Reconciled, $amount($record)),
                $day === null || $day->gt($end) => new ReconciliationResult(ReconciliationStatus::NotReconciled, $amount($record), note: 'Depois do último extrato ('.$end->format('d/m/Y').')'),
                $countsFromStart && $start !== null && $day->lt($start) => new ReconciliationResult(ReconciliationStatus::Reconciled, $amount($record), note: 'Antes do primeiro extrato'),
                default => null,
            };

            if ($result !== null) {
                $results[$record->getKey()] = $result;

                return false;
            }

            return true;
        });

        // Newest first: they are the ones not covered yet; older ones were covered first.
        $candidates
            ->sortBy([fn ($a, $b) => Carbon::parse($date($b)) <=> Carbon::parse($date($a)), fn ($a, $b) => $b->getKey() <=> $a->getKey()])
            ->each(function ($record) use (&$results, &$missing, $amount): void {
                $short = min($missing, $amount($record));
                $missing -= $short;

                $results[$record->getKey()] = $short > 0
                    ? new ReconciliationResult(ReconciliationStatus::NotReconciled, $amount($record), $short)
                    : new ReconciliationResult(ReconciliationStatus::Reconciled, $amount($record));
            });

        return $results;
    }

    private function noStatement(int $amount): ReconciliationResult
    {
        return new ReconciliationResult(ReconciliationStatus::NotReconciled, $amount, note: 'Sem extrato importado');
    }
}
