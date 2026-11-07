<?php

namespace App\Support;

use App\Enums\Frequency;
use Carbon\CarbonInterface;

/**
 * Portuguese period names: "Jan 2026", "Jan – Mar 2026", "Jun 2026 – Mai 2027".
 */
final class PeriodLabel
{
    private const MONTHS = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];

    public static function for(CarbonInterface $start, Frequency $frequency): string
    {
        $end = $start->copy()->startOfMonth()->addMonths($frequency->months() - 1);

        if ($frequency === Frequency::Monthly) {
            return self::month($start).' '.$start->year;
        }

        return $start->year === $end->year
            ? self::month($start).' – '.self::month($end).' '.$end->year
            : self::month($start).' '.$start->year.' – '.self::month($end).' '.$end->year;
    }

    private static function month(CarbonInterface $date): string
    {
        return self::MONTHS[$date->month - 1];
    }
}
