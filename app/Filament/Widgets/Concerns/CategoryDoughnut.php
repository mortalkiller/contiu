<?php

namespace App\Filament\Widgets\Concerns;

use App\Support\Money;
use MortalKiller\FilamentAppearance\Widgets\BreakdownChartWidget;
use MortalKiller\FilamentAppearance\Widgets\BreakdownChartWidget\Segment;

/**
 * Amounts per category as a ring with its legend (BreakdownChartWidget, from
 * mortalkiller/filament-appearance): at most six parts, the smallest folded into "Outras", each
 * with its amount and share, so identity never rests on colour alone. The widget brings its own
 * loading skeleton (a ring and legend lines) and shows each amount on hover.
 *
 * @mixin BreakdownChartWidget
 */
trait CategoryDoughnut
{
    /** @return array<string, int|float> category => amount, in cents */
    abstract protected function amounts(): array;

    protected function getSegments(): array
    {
        $amounts = $this->amounts();

        return array_map(
            fn (string $name, int|float $value): Segment => Segment::make($name, $value),
            array_keys($amounts),
            array_values($amounts),
        );
    }

    protected function formatValue(int|float $value): string
    {
        return Money::format((int) $value, locale: app()->getLocale());
    }
}
