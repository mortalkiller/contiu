<?php

namespace App\Support;

use App\Enums\ProjectStatus;
use App\Models\Expense;
use App\Models\OneOffIncome;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\Retainer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Dashboard figures for a year or a month, following the Excel "Resumo":
 * - contracted / outstanding / overdue look at what was contracted in the period (projects by quote
 *   date, retainer periods and one-off incomes by due date), cancelled projects excluded;
 * - received and expenses follow the cash date (payment date, expense date).
 */
final class PeriodSummary
{
    /** @var array<string, int> */
    private array $memo = [];

    public function __construct(public readonly Period $period) {}

    public function previous(): self
    {
        return new self($this->period->previous());
    }

    public function change(string $figure): Change
    {
        return Change::between($this->{$figure}(), $this->previous()->{$figure}());
    }

    public static function availableYears(): array
    {
        return Payment::query()->pluck('paid_on')->map->year
            ->merge(Expense::query()->pluck('spent_on')->map->year)
            ->merge(Project::query()->whereNotNull('quoted_on')->pluck('quoted_on')->map->year)
            ->push(today()->year)
            ->unique()->sortDesc()->values()
            ->mapWithKeys(fn (int $year) => [$year => (string) $year])->all();
    }

    public function contracted(): int
    {
        return $this->remember(__FUNCTION__, fn () => $this->contractedReceivables()->sum('amount'));
    }

    public function received(): int
    {
        return $this->remember(__FUNCTION__, fn () => Payment::query()->whereBetween('paid_on', $this->period->range())->sum('amount'));
    }

    public function outstanding(): int
    {
        return $this->remember(__FUNCTION__, fn () => $this->contractedReceivables()->sum(fn (Receivable $receivable) => $receivable->outstanding));
    }

    public function overdue(): int
    {
        return $this->remember(__FUNCTION__, fn () => $this->contractedReceivables()
            ->filter(fn (Receivable $receivable) => $receivable->due_on->lt(today()))
            ->sum(fn (Receivable $receivable) => $receivable->outstanding));
    }

    public function paidExpenses(): int
    {
        return $this->remember(__FUNCTION__, fn () => $this->expenses()->where('is_paid', true)->sum(fn (Expense $expense) => $expense->total));
    }

    public function unpaidExpenses(): int
    {
        return $this->remember(__FUNCTION__, fn () => $this->expenses()->where('is_paid', false)->sum(fn (Expense $expense) => $expense->total));
    }

    public function cashResult(): int
    {
        return $this->received() - $this->paidExpenses();
    }

    public function forecastResult(): int
    {
        return $this->contracted() - $this->paidExpenses() - $this->unpaidExpenses();
    }

    /** Always from today, whatever the period. */
    public static function dueNext30Days(): int
    {
        return (int) Receivable::query()->open()->excludingCancelled()->with('payments')
            ->whereBetween('due_on', [today()->startOfDay()->toDateTimeString(), today()->addDays(30)->endOfDay()->toDateTimeString()])
            ->get()->sum(fn (Receivable $receivable) => $receivable->outstanding);
    }

    /**
     * Months of the year, or days of the month.
     *
     * @return array<int, array{label: string, received: int, paid: int, result: int}>
     */
    public function cashFlow(): array
    {
        $buckets = [];
        $cursor = $this->period->start;

        while ($cursor->lte($this->period->end)) {
            $key = $this->bucketKey($cursor);
            $buckets[$key] = [
                'label' => $this->period->isMonth() ? (string) $cursor->day : Period::SHORT_MONTHS[$cursor->month - 1],
                'received' => 0,
                'paid' => 0,
                'result' => 0,
            ];
            $cursor = $this->period->isMonth() ? $cursor->addDay() : $cursor->addMonth();
        }

        foreach (Payment::query()->whereBetween('paid_on', $this->period->range())->get() as $payment) {
            $buckets[$this->bucketKey($payment->paid_on)]['received'] += $payment->amount;
        }

        foreach ($this->expenses()->where('is_paid', true) as $expense) {
            $buckets[$this->bucketKey($expense->spent_on)]['paid'] += $expense->total;
        }

        return array_values(array_map(fn (array $bucket) => [
            ...$bucket,
            'received' => $bucket['received'],
            'paid' => $bucket['paid'],
            'result' => $bucket['received'] - $bucket['paid'],
        ], $buckets));
    }

    /** @return array<string, int> income category => cents received in the period, largest first */
    public function receivedByCategory(): array
    {
        return Payment::query()
            ->with('receivable.billable.category')
            ->whereBetween('paid_on', $this->period->range())
            ->get()
            ->groupBy(fn (Payment $payment) => $payment->receivable?->billable?->category?->name ?? 'Sem categoria')
            ->map(fn (Collection $payments) => (int) $payments->sum('amount'))
            ->sortDesc()
            ->all();
    }

    /** @return array<string, int> category => cents paid, largest first */
    public function paidExpensesByCategory(): array
    {
        return $this->expenses()->where('is_paid', true)
            ->groupBy(fn (Expense $expense) => $expense->category?->name ?? 'Sem categoria')
            ->map(fn (Collection $expenses) => (int) $expenses->sum(fn (Expense $expense) => $expense->total))
            ->sortDesc()
            ->all();
    }

    /**
     * Receivables of what was contracted in the period (see class docblock).
     *
     * @return Builder<Receivable>
     */
    public function contractedReceivablesQuery(): Builder
    {
        return Receivable::query()
            ->with('payments')
            ->where(fn (Builder $query) => $query
                ->whereHasMorph('billable', [Project::class], fn (Builder $query) => $query
                    ->where('status', '!=', ProjectStatus::Cancelled)
                    ->whereBetween('quoted_on', $this->period->range()))
                ->orWhere(fn (Builder $query) => $query
                    ->whereIn('billable_type', [Retainer::class, OneOffIncome::class])
                    ->whereBetween('due_on', $this->period->range())));
    }

    /** @return Collection<int, Receivable> */
    private function contractedReceivables(): Collection
    {
        return $this->contractedReceivablesQuery()->get();
    }

    /** @return Collection<int, Expense> */
    private function expenses(): Collection
    {
        return Expense::query()->with('category')->whereBetween('spent_on', $this->period->range())->get();
    }

    private function bucketKey(\DateTimeInterface $date): string
    {
        return $this->period->isMonth() ? $date->format('Y-m-d') : $date->format('Y-m');
    }

    private function remember(string $key, callable $compute): int
    {
        return $this->memo[$key] ??= (int) $compute();
    }
}
