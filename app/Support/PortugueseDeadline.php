<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * When a legal deadline really ends (OBR03, SS052, SS061).
 *
 * - A deadline on a Saturday, Sunday or national holiday moves to the next working day (Código Civil
 *   art. 279.º, CPPT art. 20.º; RCRC art. 57.º-B for the Social Security declarations; ISS guidance
 *   for the contributions).
 * - A deadline in August ends on 31 August, working day or not (LGT art. 57.º-A, n.º 1, for tax
 *   obligations; the same rule for the Social Security contributions due in August).
 *
 * Only the national holidays count: municipal holidays and Carnival do not move a deadline.
 */
final class PortugueseDeadline
{
    public static function of(CarbonImmutable $deadline): CarbonImmutable
    {
        $deadline = $deadline->startOfDay();

        if ($deadline->month === 8) {
            return $deadline->endOfMonth()->startOfDay();
        }

        return self::nextWorkingDay($deadline);
    }

    /** The day itself when it is a working day, else the first working day after it. */
    public static function nextWorkingDay(CarbonImmutable $day): CarbonImmutable
    {
        $day = $day->startOfDay();

        while (! self::isWorkingDay($day)) {
            $day = $day->addDay();
        }

        return $day;
    }

    public static function isWorkingDay(CarbonImmutable $day): bool
    {
        return ! $day->isWeekend() && ! in_array($day->format('m-d'), self::holidays($day->year), true);
    }

    /**
     * The national holidays of a year (Código do Trabalho, art. 234.º), as "mm-dd".
     *
     * @return list<string>
     */
    public static function holidays(int $year): array
    {
        $easter = CarbonImmutable::create($year, 3, 21)->addDays(easter_days($year));

        return [
            '01-01',
            $easter->subDays(2)->format('m-d'), // Good Friday
            $easter->format('m-d'),
            '04-25',
            '05-01',
            $easter->addDays(60)->format('m-d'), // Corpus Christi
            '06-10',
            '08-15',
            '10-05',
            '11-01',
            '12-01',
            '12-08',
            '12-25',
        ];
    }
}
