<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * "in 5 days", "13 days ago" or "today": how far a due date is from today, as the lists say it
 * under the date.
 */
final class RelativeDue
{
    public static function describe(CarbonInterface $due): string
    {
        $days = (int) today()->diffInDays($due, absolute: false);

        return match (true) {
            $days > 0 => trans_choice('{1} in :count day|[2,*] in :count days', $days),
            $days < 0 => trans_choice('{1} :count day ago|[2,*] :count days ago', -$days),
            default => __('today'),
        };
    }

    public static function isPast(CarbonInterface $due): bool
    {
        return $due->isBefore(today());
    }
}
