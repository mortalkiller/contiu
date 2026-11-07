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
 * Out: unpaid expenses + recurring expenses not generated yet + estimated Segurança Social.
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

    public function startsFromBankBalance(): bool
    {
        return $this->settings->currentBankBalance() !== null;
    }

    public function startingBalance(): float
    {
        return round($this->settings->currentBankBalance() ?? 0.0, 2);
    }

    /**
     * @return array<int, array{month: CarbonImmutable, label: string, inflows: float, overdueInflows: float, projectedInflows: float, outflows: float, overdueOutflows: float, projectedOutflows: float, socialSecurity: float, net: float, balance: float}>
     */
    public function months(): array
    {
        return $this->rows ??= $this->build();
    }

    /** @return array{inflows: float, outflows: float, net: float, endBalance: float, lowestBalance: float} */
    public function totals(): array
    {
        $rows = $this->months();
        $inflows = round(array_sum(array_column($rows, 'inflows')), 2);
        $outflows = round(array_sum(array_column($rows, 'outflows')), 2);

        return [
            'inflows' => $inflows,
            'outflows' => $outflows,
            'net' => round($inflows - $outflows, 2),
            'endBalance' => (float) (end($rows)['balance'] ?? $this->startingBalance()),
            'lowestBalance' => (float) min(array_column($rows, 'balance') ?: [$this->startingBalance()]),
        ];
    }

    /** @return array<string, float> where the expected money comes from, over the whole horizon */
    public function inflowBreakdown(): array
    {
        $rows = $this->months();
        $sum = fn (string $field) => round(array_sum(array_column($rows, $field)), 2);

        return [
            'A receber' => round($sum('inflows') - $sum('overdueInflows') - $sum('projectedInflows'), 2),
            'Em atraso' => $sum('overdueInflows'),
            'Avenças por gerar' => $sum('projectedInflows'),
        ];
    }

    /** @return array<string, float> expected money in per client (same rules as the inflows), largest first */
    public function inflowsByClient(): array
    {
        $byClient = [];
        $add = function (?string $client, float $amount) use (&$byClient): void {
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

        return array_map(fn (float $amount) => round($amount, 2), $byClient);
    }

    /** @return array<string, float> where the expected money goes, over the whole horizon */
    public function outflowBreakdown(): array
    {
        $rows = $this->months();
        $sum = fn (string $field) => round(array_sum(array_column($rows, $field)), 2);

        return [
            'Despesas por pagar' => round($sum('outflows') - $sum('overdueOutflows') - $sum('projectedOutflows') - $sum('socialSecurity'), 2),
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
                'inflows' => 0.0,
                'overdueInflows' => 0.0,
                'projectedInflows' => 0.0,
                'outflows' => 0.0,
                'overdueOutflows' => 0.0,
                'projectedOutflows' => 0.0,
                'socialSecurity' => 0.0,
            ];
        }

        $add = function (CarbonImmutable $date, float $amount, string $kind, bool $projected = false) use (&$buckets): void {
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

        // Out: Segurança Social.
        $estimator = new TaxEstimator($this->settings, $this->start->year);

        foreach ($buckets as $key => $bucket) {
            $contribution = $estimator->ssContributionForMonth($bucket['month']);
            $buckets[$key]['socialSecurity'] = $contribution;
            $buckets[$key]['outflows'] += $contribution;
        }

        $balance = $this->startingBalance();
        $rows = [];

        foreach ($buckets as $bucket) {
            foreach (['inflows', 'overdueInflows', 'projectedInflows', 'outflows', 'overdueOutflows', 'projectedOutflows', 'socialSecurity'] as $field) {
                $bucket[$field] = round($bucket[$field], 2);
            }

            $bucket['net'] = round($bucket['inflows'] - $bucket['outflows'], 2);
            $balance = round($balance + $bucket['net'], 2);
            $bucket['balance'] = $balance;
            $rows[] = $bucket;
        }

        return $rows;
    }
}
