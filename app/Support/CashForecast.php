<?php

namespace App\Support;

use App\Models\Expense;
use App\Models\Receivable;
use App\Models\RecurringExpense;
use App\Models\Retainer;
use Carbon\CarbonImmutable;

/**
 * Cash forecast for the current month and the following ones.
 *
 * In:  open amounts of existing receivables (cancelled projects excluded) + retainer periods not
 *      generated yet (projected with the generator's rules).
 * Out: unpaid expenses + recurring expenses not generated yet + estimated Segurança Social (what is
 *      paid in the month: the contribution of the month before).
 * Anything already due before the current month rolls into the current month and is flagged.
 * The running balance starts from the bank balance setting (or 0 when it isn't set).
 */
final class CashForecast
{
    /** @var array<int, array<string, mixed>>|null */
    private ?array $rows = null;

    public readonly CarbonImmutable $start;

    public readonly CarbonImmutable $end;

    public function __construct(private readonly FinanceSettings $settings, public readonly int $monthsAhead = 3)
    {
        $this->start = CarbonImmutable::today()->startOfMonth();
        $this->end = $this->start->addMonths(max(1, $monthsAhead) - 1)->endOfMonth();
    }

    /**
     * A running balance needs a bank account to start from: without a bank account for the
     * activity the forecast is only what comes in and goes out, month by month.
     */
    public function showsBalance(): bool
    {
        return $this->settings->hasBusinessAccount();
    }

    /** True once a bank statement with balances was imported. */
    public function startsFromBankBalance(): bool
    {
        return $this->account()->openingBalance() !== null;
    }

    /** The statement's balance plus what was recorded since, up to today (cents); 0 without a statement. */
    public function startingBalance(): int
    {
        return $this->account()->contiuBalanceAt(CarbonImmutable::today()) ?? 0;
    }

    public function account(): CompanyAccount
    {
        return $this->account ??= new CompanyAccount;
    }

    private ?CompanyAccount $account = null;

    /**
     * @return array<int, array{month: CarbonImmutable, label: string, inflows: int, overdueInflows: int, projectedInflows: int, outflows: int, overdueOutflows: int, projectedOutflows: int, socialSecurity: int, net: int, balance: int}> (cents)
     */
    public function months(): array
    {
        return $this->rows ??= $this->build();
    }

    /** @return array{inflows: int, outflows: int, net: int, endBalance: int, lowestBalance: int} (cents) */
    public function totals(): array
    {
        $rows = $this->months();
        $inflows = (int) array_sum(array_column($rows, 'inflows'));
        $outflows = (int) array_sum(array_column($rows, 'outflows'));

        return [
            'inflows' => $inflows,
            'outflows' => $outflows,
            'net' => $inflows - $outflows,
            'endBalance' => (int) (end($rows)['balance'] ?? $this->startingBalance()),
            'lowestBalance' => (int) min(array_column($rows, 'balance') ?: [$this->startingBalance()]),
        ];
    }

    /** @return array<string, int> where the expected money (cents) comes from, over the whole horizon */
    public function inflowBreakdown(): array
    {
        $rows = $this->months();
        $sum = fn (string $field) => (int) array_sum(array_column($rows, $field));

        return [
            'A receber' => $sum('inflows') - $sum('overdueInflows') - $sum('projectedInflows'),
            'Em atraso' => $sum('overdueInflows'),
            'Avenças por gerar' => $sum('projectedInflows'),
        ];
    }

    /** @return array<string, int> expected money (cents) in per client (same rules as the inflows), largest first */
    public function inflowsByClient(): array
    {
        $byClient = [];
        $add = function (?string $client, int $amount) use (&$byClient): void {
            $client ??= '—';
            $byClient[$client] = ($byClient[$client] ?? 0) + $amount;
        };

        Receivable::query()->open()->excludingCancelled()->with(['payments', 'billable.client'])
            ->whereDate('due_on', '<=', $this->end)
            ->get()
            ->each(fn (Receivable $receivable) => $add($receivable->client?->name, $receivable->outstanding));

        $projector = new ScheduleProjector;

        Retainer::query()->where('is_active', true)->with('client')->get()->each(function (Retainer $retainer) use ($projector, $add): void {
            foreach ($projector->retainerDueDates($retainer, $this->end) as $date) {
                $add($retainer->client?->name, $retainer->total);
            }
        });

        arsort($byClient);

        return $byClient;
    }

    /** @return array<string, int> where the expected money (cents) goes, over the whole horizon */
    public function outflowBreakdown(): array
    {
        $rows = $this->months();
        $sum = fn (string $field) => (int) array_sum(array_column($rows, $field));

        return [
            'Despesas por pagar' => $sum('outflows') - $sum('overdueOutflows') - $sum('projectedOutflows') - $sum('socialSecurity'),
            'Despesas em atraso' => $sum('overdueOutflows'),
            'Recorrentes previstas' => $sum('projectedOutflows'),
            'Segurança Social' => $sum('socialSecurity'),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function build(): array
    {
        $buckets = [];

        for ($month = $this->start; $month->lte($this->end); $month = $month->addMonth()) {
            $buckets[$month->format('Y-m')] = [
                'month' => $month,
                'label' => Period::MONTHS[$month->month - 1].' '.$month->year,
                'inflows' => 0,
                'overdueInflows' => 0,
                'projectedInflows' => 0,
                'outflows' => 0,
                'overdueOutflows' => 0,
                'projectedOutflows' => 0,
                'socialSecurity' => 0,
            ];
        }

        $add = function (CarbonImmutable $date, int $amount, string $kind, bool $projected = false) use (&$buckets): void {
            $overdue = $date->lt($this->start);
            $key = ($overdue ? $this->start : $date)->format('Y-m');

            $buckets[$key][$kind] += $amount;

            if ($overdue) {
                $buckets[$key][$kind === 'inflows' ? 'overdueInflows' : 'overdueOutflows'] += $amount;
            }

            if ($projected) {
                $buckets[$key][$kind === 'inflows' ? 'projectedInflows' : 'projectedOutflows'] += $amount;
            }
        };

        // In: existing open receivables.
        Receivable::query()->open()->excludingCancelled()->with('payments')
            ->whereDate('due_on', '<=', $this->end)
            ->get()
            ->each(fn (Receivable $receivable) => $add(CarbonImmutable::parse($receivable->due_on), $receivable->outstanding, 'inflows'));

        // In: retainer periods the daily job has not generated yet.
        $projector = new ScheduleProjector;

        Retainer::query()->where('is_active', true)->get()->each(function (Retainer $retainer) use ($projector, $add): void {
            foreach ($projector->retainerDueDates($retainer, $this->end) as $date) {
                $add($date, $retainer->total, 'inflows', projected: true);
            }
        });

        // Out: unpaid expenses.
        Expense::query()->where('is_paid', false)
            ->whereDate('spent_on', '<=', $this->end)
            ->get()
            ->each(fn (Expense $expense) => $add(CarbonImmutable::parse($expense->spent_on), $expense->total, 'outflows'));

        // Out: recurring expenses not generated yet.
        RecurringExpense::query()->where('is_active', true)->get()->each(function (RecurringExpense $template) use ($projector, $add): void {
            foreach ($projector->recurringExpenseDates($template, $this->end) as $date) {
                $add($date, $template->total, 'outflows', projected: true);
            }
        });

        // Out: Segurança Social paid in the month (the contribution of the month before).
        $estimator = new TaxEstimator($this->settings, $this->start->year);

        foreach ($buckets as $key => $bucket) {
            $payment = $estimator->ssPaymentInMonth($bucket['month']);
            $buckets[$key]['socialSecurity'] = $payment;
            $buckets[$key]['outflows'] += $payment;
        }

        $balance = $this->startingBalance();
        $rows = [];

        foreach ($buckets as $bucket) {
            $bucket['net'] = $bucket['inflows'] - $bucket['outflows'];
            $balance += $bucket['net'];
            $bucket['balance'] = $balance;
            $rows[] = $bucket;
        }

        return $rows;
    }
}
