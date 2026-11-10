<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Expense;
use App\Models\Receivable;
use App\Models\RecurringExpense;
use App\Models\Retainer;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

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
    public const MAX_DELAY_DAYS = 365;

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

    /** @return array{reserved: int, available: ?int, paidThisYear: int} */
    public function reserve(): array
    {
        $today = CarbonImmutable::today();
        $reserved = $this->settings->taxReservedAmount();
        $paid = AnnualFiscalReview::taxPayments($today->year, through: $today)
            ->get()
            ->sum(fn (Expense $expense) => $expense->total);

        return [
            'reserved' => $reserved,
            'available' => $this->showsBalance() && $this->startsFromBankBalance() ? $this->startingBalance() - $reserved : null,
            'paidThisYear' => (int) $paid,
        ];
    }

    public function account(): CompanyAccount
    {
        return $this->account ??= new CompanyAccount;
    }

    private ?CompanyAccount $account = null;

    /**
     * @return array<int, array{month: CarbonImmutable, label: string, inflows: int, overdueInflows: int, projectedInflows: int, outflows: int, overdueOutflows: int, projectedOutflows: int, socialSecurity: int, socialSecurityConfirmed: bool, confirmedVat: int, net: int, balance: int}> (cents)
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
        return $this->groupClientInflows(fn (?Client $client): string => $client?->name ?? '—');
    }

    /** @return array<int, int> */
    public function inflowsByClientId(): array
    {
        return $this->groupClientInflows(fn (?Client $client): int => $client?->id ?? 0);
    }

    /** @return array{inflows: int, clientInflows: int, withoutClient: int, withDelay?: int, beyondHorizon?: int, months?: list<array{label: string, inflows: int, withDelay: int}>} */
    public function clientInflowScenario(int $clientId, ?int $delayDays): array
    {
        if ($delayDays !== null && ($delayDays < 0 || $delayDays > self::MAX_DELAY_DAYS)) {
            throw new InvalidArgumentException('Delay days are outside the forecast simulation range.');
        }

        $inflows = 0;
        $clientInflows = 0;
        $beyondHorizon = 0;
        $months = [];

        if ($delayDays !== null) {
            for ($month = $this->start; $month->lte($this->end); $month = $month->addMonth()) {
                $months[$month->format('Y-m')] = [
                    'label' => Period::MONTHS[$month->month - 1].' '.$month->year,
                    'inflows' => 0,
                    'withDelay' => 0,
                ];
            }
        }

        foreach ($this->expectedInflows() as [$date, $amount, $client]) {
            $belongsToClient = ($client?->id ?? 0) === $clientId;
            $inflows += $amount;
            $clientInflows += $belongsToClient ? $amount : 0;

            if ($delayDays === null) {
                continue;
            }

            $months[$this->inflowMonth($date)]['inflows'] += $amount;
            $delayedDate = $belongsToClient ? $date->addDays($delayDays) : $date;

            if ($delayedDate->gt($this->end)) {
                $beyondHorizon += $amount;
            } else {
                $months[$this->inflowMonth($delayedDate)]['withDelay'] += $amount;
            }
        }

        $scenario = ['inflows' => $inflows, 'clientInflows' => $clientInflows, 'withoutClient' => $inflows - $clientInflows];

        return $delayDays === null ? $scenario : [
            ...$scenario,
            'withDelay' => $inflows - $beyondHorizon,
            'beyondHorizon' => $beyondHorizon,
            'months' => array_values($months),
        ];
    }

    private function inflowMonth(CarbonImmutable $date): string
    {
        return $date->max($this->start)->format('Y-m');
    }

    /** @return array<array-key, int> */
    private function groupClientInflows(callable $key): array
    {
        $byClient = [];

        foreach ($this->expectedInflows() as [, $amount, $client]) {
            $group = $key($client);
            $byClient[$group] = ($byClient[$group] ?? 0) + $amount;
        }

        arsort($byClient);

        return $byClient;
    }

    /** @return iterable<array{CarbonImmutable, int, ?Client, bool}> Date, amount, client and whether still to be generated. */
    private function expectedInflows(bool $withClient = true): iterable
    {
        $receivables = Receivable::query()->open()->excludingCancelled()->with('payments')
            ->when($withClient, fn ($query) => $query->with('billable.client'))
            ->whereDate('due_on', '<=', $this->end)
            ->get();

        foreach ($receivables as $receivable) {
            yield [CarbonImmutable::parse($receivable->due_on), $receivable->outstanding, $withClient ? $receivable->client : null, false];
        }

        $projector = new ScheduleProjector;
        $retainers = Retainer::query()->where('is_active', true)
            ->when($withClient, fn ($query) => $query->with('client'))
            ->get();

        foreach ($retainers as $retainer) {
            foreach ($projector->retainerDueDates($retainer, $this->end) as $date) {
                yield [$date, $retainer->total, $withClient ? $retainer->client : null, true];
            }
        }
    }

    /** @return array<string, int> where the expected money (cents) goes, over the whole horizon */
    public function outflowBreakdown(): array
    {
        $rows = $this->months();
        $sum = fn (string $field) => (int) array_sum(array_column($rows, $field));

        return [
            'Despesas por pagar' => $sum('outflows') - $sum('overdueOutflows') - $sum('projectedOutflows') - $sum('socialSecurity') - $sum('confirmedVat'),
            'Despesas em atraso' => $sum('overdueOutflows'),
            'Recorrentes previstas' => $sum('projectedOutflows'),
            'Segurança Social' => $sum('socialSecurity'),
            'IVA confirmado' => $sum('confirmedVat'),
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
                'socialSecurityConfirmed' => false,
                'confirmedVat' => 0,
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

        foreach ($this->expectedInflows(withClient: false) as [$date, $amount, , $projected]) {
            $add($date, $amount, 'inflows', $projected);
        }

        $projector = new ScheduleProjector;

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

        $obligations = new TaxObligations($this->settings, CarbonImmutable::today());

        foreach ($buckets as $key => $bucket) {
            $contribution = $obligations->forecastSocialSecurity($bucket['month']);
            $buckets[$key]['socialSecurity'] = $contribution['amount'];
            $buckets[$key]['socialSecurityConfirmed'] = $contribution['confirmed'];
            $buckets[$key]['outflows'] += $contribution['amount'];
        }

        foreach ($obligations->forecastVat($this->end) as $vat) {
            $key = $vat['dueOn']->max($this->start)->format('Y-m');
            $buckets[$key]['confirmedVat'] += $vat['amount'];
            $buckets[$key]['outflows'] += $vat['amount'];
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
