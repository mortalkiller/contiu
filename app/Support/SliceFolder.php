<?php

namespace App\Support;

/**
 * Pie/doughnut slices: at most six — past that, the largest five stay and the rest become "Outras"
 * (a 7th+ colour would no longer be told apart, see the dataviz series-count ladder).
 */
final class SliceFolder
{
    /**
     * @param  array<string, int|float>  $values
     * @return array<string, int|float>
     */
    public static function fold(array $values, int $max = 6): array
    {
        if (count($values) <= $max) {
            return $values;
        }

        arsort($values);

        $kept = array_slice($values, 0, $max - 1, true);
        $kept[__('Other categories')] = array_sum(array_slice($values, $max - 1, null, true));

        return $kept;
    }
}
