<?php

namespace App\Support;

use App\Models\RecurringExpense;
use App\Models\Retainer;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Read-only projection of the dates App\Actions\GenerateRetainerPeriods and
 * App\Actions\GenerateRecurringExpenses would create, without writing anything: continue after the
 * latest existing record (never back-fill gaps), otherwise start on the first due day on/after the
 * start date; stop at the end date. Kept in step with the generators by CashForecastTest.
 *
 * @phpstan-type Dates array<int, CarbonImmutable>
 */
final class ScheduleProjector
{
    /** @return array<int, CarbonImmutable> */
    public function retainerDueDates(Retainer $retainer, CarbonInterface $until): array
    {
        if (! $retainer->is_active) {
            return [];
        }

        return $this->dates(
            latest: $retainer->receivables()->max('due_on'),
            startsOn: CarbonImmutable::parse($retainer->starts_on),
            endsOn: $retainer->ends_on ? CarbonImmutable::parse($retainer->ends_on) : null,
            day: (int) $retainer->due_day,
            months: $retainer->frequency->months(),
            until: CarbonImmutable::parse($until),
        );
    }

    /** @return array<int, CarbonImmutable> */
    public function recurringExpenseDates(RecurringExpense $template, CarbonInterface $until): array
    {
        if (! $template->is_active) {
            return [];
        }

        return $this->dates(
            latest: $template->expenses()->max('spent_on'),
            startsOn: CarbonImmutable::parse($template->starts_on),
            endsOn: $template->ends_on ? CarbonImmutable::parse($template->ends_on) : null,
            day: (int) $template->day,
            months: $template->frequency->months(),
            until: CarbonImmutable::parse($until),
        );
    }

    /** @return array<int, CarbonImmutable> */
    private function dates(?string $latest, CarbonImmutable $startsOn, ?CarbonImmutable $endsOn, int $day, int $months, CarbonImmutable $until): array
    {
        if ($latest !== null) {
            $next = $this->onDay(CarbonImmutable::parse($latest)->startOfMonth()->addMonths($months), $day);
        } else {
            $next = $this->onDay($startsOn->startOfMonth(), $day);

            if ($next->lt($startsOn)) {
                $next = $this->onDay($next->startOfMonth()->addMonths($months), $day);
            }
        }

        $dates = [];

        while ($next->lte($until) && ($endsOn === null || $next->lte($endsOn))) {
            $dates[] = $next;
            $next = $this->onDay($next->startOfMonth()->addMonths($months), $day);
        }

        return $dates;
    }

    private function onDay(CarbonImmutable $month, int $day): CarbonImmutable
    {
        return $month->day(min($day, $month->daysInMonth))->startOfDay();
    }
}
