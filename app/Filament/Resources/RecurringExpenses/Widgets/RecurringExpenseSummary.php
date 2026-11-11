<?php

namespace App\Filament\Resources\RecurringExpenses\Widgets;

use App\Enums\Frequency;
use App\Filament\Resources\RecurringExpenses\Schemas\RecurringExpenseHeader;
use App\Filament\Support\DetailPage;
use App\Models\Expense;
use App\Models\RecurringExpense;
use App\Support\Money;
use App\Support\RelativeDue;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use MortalKiller\FilamentAppearance\Widgets\Concerns\GroupsStats;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

/**
 * The four figures under a recurring expense's title, as in the mock, in one card: what each
 * period costs, when the next expense is created, what this year's expenses add up to (and
 * where the year ends at this pace) and what is still to pay.
 */
class RecurringExpenseSummary extends StatsOverviewWidget
{
    use GroupsStats;
    use HasSkeletonPlaceholder;

    public ?RecurringExpense $record = null;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int|array
    {
        return ['md' => 2, 'xl' => 4];
    }

    protected function getStats(): array
    {
        $template = $this->record;

        if (! $template instanceof RecurringExpense) {
            return [];
        }

        $year = today()->year;
        $upcoming = RecurringExpenseHeader::upcomingDates($template);
        // One query for both figures; summed with the model's own total, so the cents match every other screen.
        $expenses = $template->expenses()
            ->where(fn (Builder $query) => $query->whereYear('spent_on', $year)->orWhere('is_paid', false))
            ->orderBy('spent_on')
            ->get();

        return [
            $this->perPeriod($template),
            $this->next($template, $upcoming[0] ?? null),
            $this->spentThisYear($template, $expenses->filter(fn (Expense $expense): bool => $expense->spent_on->year === $year), $upcoming, $year),
            $this->unpaid($expenses->reject(fn (Expense $expense): bool => (bool) $expense->is_paid)->values()),
        ];
    }

    /** "Por mês, com IVA": the total of one expense, over its amount before VAT and the rate. */
    private function perPeriod(RecurringExpense $template): Stat
    {
        return Stat::make(match ($template->frequency) {
            Frequency::Monthly => __('Per month, with VAT'),
            Frequency::Quarterly => __('Per quarter, with VAT'),
            Frequency::Yearly => __('Per year, with VAT'),
        }, Money::format($template->total))
            ->description(__(':amount excluding VAT · :vat', [
                'amount' => Money::format($template->net_amount),
                'vat' => __('VAT at :rate', ['rate' => Number::percentage((float) $template->vat_rate * 100, maxPrecision: 2, locale: app()->getLocale())]),
            ]));
    }

    /** "Próxima": the date of the next expense the generator creates, or why there is none. */
    private function next(RecurringExpense $template, ?CarbonImmutable $next): Stat
    {
        if ($next === null) {
            return Stat::make(__('Next expense'), '—')->description(match (true) {
                ! $template->is_active => __('Paused: it creates no expenses'),
                RecurringExpenseHeader::hasEnded($template) => __('Ended on :date', ['date' => DetailPage::date($template->ends_on)]),
                default => __('No more expenses before its end date'),
            });
        }

        $when = ['when' => Str::ucfirst(RelativeDue::describe($next))];

        return Stat::make(__('Next expense'), DetailPage::date($next))
            ->description($next->isBefore(today()) ? __(':when · still to be created', $when) : __(':when · created on its own', $when));
    }

    /**
     * "Gasto em 2026": this year's expenses and, while more are due before the year ends, what
     * the year adds up to with them at today's amount.
     *
     * @param  Collection<int, Expense>  $thisYear
     * @param  list<CarbonImmutable>  $upcoming
     */
    private function spentThisYear(RecurringExpense $template, Collection $thisYear, array $upcoming, int $year): Stat
    {
        $spent = $this->total($thisYear);
        $stillThisYear = count(array_filter($upcoming, fn (CarbonImmutable $date): bool => $date->year === $year));
        $count = trans_choice('{0} No expenses|{1} 1 expense|[2,*] :count expenses', $thisYear->count());

        return Stat::make(__('Spent in :year', ['year' => $year]), Money::format($spent))
            ->description($stillThisYear === 0 ? $count : __(':expenses · :amount by the end of the year', [
                'expenses' => $count,
                'amount' => Money::format($spent + $stillThisYear * $template->total),
            ]));
    }

    /**
     * "Por pagar": what the created expenses still owe, with the month of the one in question
     * (or how many they are) and since when.
     *
     * @param  Collection<int, Expense>  $unpaid
     */
    private function unpaid(Collection $unpaid): Stat
    {
        $oldest = $unpaid->first();

        if ($oldest === null) {
            return Stat::make(__('To pay'), '—')->description(__('Nothing left to pay'));
        }

        return Stat::make(__('To pay'), Money::format($this->total($unpaid)))
            ->description(__(':what · since :date', [
                'what' => $unpaid->count() === 1
                    ? Str::ucfirst($oldest->spent_on->locale(app()->getLocale())->translatedFormat($oldest->spent_on->isCurrentYear() ? 'F' : 'F Y'))
                    : trans_choice('{1} :count unpaid expense|[2,*] :count unpaid expenses', $unpaid->count()),
                'date' => DetailPage::date($oldest->spent_on),
            ]))
            ->valueColor('warning');
    }

    /** @param Collection<int, Expense> $expenses */
    private function total(Collection $expenses): int
    {
        return (int) $expenses->sum(fn (Expense $expense): int => (int) $expense->total);
    }
}
