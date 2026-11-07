<?php

namespace App\Support;

use App\Models\Expense;
use App\Models\Payment;
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

    public function __construct(CarbonInterface $month)
    {
        $this->start = CarbonImmutable::parse($month)->startOfMonth();
        $this->end = $this->start->endOfMonth();
    }

    /** @return Builder<Receivable> */
    public function receivablesQuery(): Builder
    {
        return Receivable::query()
            ->excludingCancelled()
            ->where(fn (Builder $query) => $query
                ->whereBetween('due_on', $this->range())
                ->orWhere(fn (Builder $query) => $query->whereDate('due_on', '<', $this->start)->open())
                // Due later, but part of it was paid this month (e.g. an advance on a January instalment).
                ->orWhere(fn (Builder $query) => $query
                    ->whereDate('due_on', '>', $this->end)
                    ->whereHas('payments', fn (Builder $query) => $query->whereBetween('paid_on', $this->range()))))
            ->with(['payments', 'billable.client'])
            ->orderBy('due_on')
            ->orderBy('id');
    }

    /** @return Collection<int, Receivable> */
    public function receivables(): Collection
    {
        return $this->receivablesQuery()->get();
    }

    /** @return Builder<Expense> */
    public function expensesQuery(): Builder
    {
        return Expense::query()
            ->where(fn (Builder $query) => $query
                ->whereBetween('spent_on', $this->range())
                ->orWhere(fn (Builder $query) => $query->whereDate('spent_on', '<', $this->start)->where('is_paid', false)))
            ->with(['supplier', 'category', 'project', 'retainer'])
            ->orderBy('spent_on')
            ->orderBy('id');
    }

    /** @return Collection<int, Expense> */
    public function expenses(): Collection
    {
        return $this->expensesQuery()->get();
    }

    /** @return array{0: string, 1: string} Last second included: SQLite stores dates with a time part. */
    private function range(): array
    {
        return [$this->start->startOfDay()->toDateTimeString(), $this->end->endOfDay()->toDateTimeString()];
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
        return $this->received() - $this->paidExpenses();
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

    public function pendingCount(): int
    {
        return $this->pendingReceivables() + $this->pendingExpenses();
    }

    public function isClosed(): bool
    {
        return $this->pendingCount() === 0;
    }
}
