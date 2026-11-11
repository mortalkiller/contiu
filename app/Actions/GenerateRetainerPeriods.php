<?php

namespace App\Actions;

use App\Models\Retainer;
use App\Support\PeriodLabel;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Creates the receivable for each retainer period that falls due up to a horizon.
 *
 * It always continues after the latest existing period and never back-fills older gaps,
 * so periods that were skipped or deleted on purpose are not recreated.
 */
class GenerateRetainerPeriods
{
    public const DAYS_AHEAD = 30;

    /** @return int number of receivables created */
    public function forAll(?CarbonInterface $until = null): int
    {
        $until ??= today()->addDays(self::DAYS_AHEAD);

        return Retainer::query()->where('is_active', true)->get()
            ->sum(fn (Retainer $retainer) => $this->forRetainer($retainer, $until));
    }

    /** @return int number of receivables created */
    public function forRetainer(Retainer $retainer, ?CarbonInterface $until = null): int
    {
        if (! $retainer->is_active) {
            return 0;
        }

        $until ??= today()->addDays(self::DAYS_AHEAD);
        $months = $retainer->frequency->months();
        $created = 0;

        for ($dueOn = $this->firstDueDate($retainer); $dueOn->lte($until); $dueOn = $this->dueDay($dueOn->copy()->startOfMonth()->addMonths($months), $retainer->due_day)) {
            if ($retainer->ends_on !== null && $dueOn->gt($retainer->ends_on)) {
                break;
            }

            $retainer->receivables()->create([
                'description' => PeriodLabel::for($dueOn, $retainer->frequency),
                'amount' => $retainer->total,
                'due_on' => $dueOn,
            ]);

            $created++;
        }

        return $created;
    }

    /**
     * The next periods the retainer will bill, not created yet: for "Próximos meses" on its page.
     * Empty for a retainer that ended.
     *
     * @return list<array{label: string, due_on: Carbon, amount: int}>
     */
    public function upcoming(Retainer $retainer, int $count = 3): array
    {
        if (! $retainer->is_active) {
            return [];
        }

        $months = $retainer->frequency->months();
        $periods = [];

        for ($dueOn = $this->firstDueDate($retainer); count($periods) < $count; $dueOn = $this->dueDay($dueOn->copy()->startOfMonth()->addMonths($months), $retainer->due_day)) {
            if ($retainer->ends_on !== null && $dueOn->gt($retainer->ends_on)) {
                break;
            }

            $periods[] = ['label' => PeriodLabel::for($dueOn, $retainer->frequency), 'due_on' => $dueOn->copy(), 'amount' => (int) $retainer->total];
        }

        return $periods;
    }

    private function firstDueDate(Retainer $retainer): Carbon
    {
        $months = $retainer->frequency->months();
        $latest = $retainer->receivables()->max('due_on');

        if ($latest !== null) {
            return $this->dueDay(Carbon::parse($latest)->startOfMonth()->addMonths($months), $retainer->due_day);
        }

        $first = $this->dueDay($retainer->starts_on->copy()->startOfMonth(), $retainer->due_day);

        return $first->lt($retainer->starts_on)
            ? $this->dueDay($first->copy()->startOfMonth()->addMonths($months), $retainer->due_day)
            : $first;
    }

    private function dueDay(Carbon $month, int $day): Carbon
    {
        return $month->day(min($day, $month->daysInMonth));
    }
}
