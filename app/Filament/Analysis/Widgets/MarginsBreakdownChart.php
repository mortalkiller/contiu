<?php

namespace App\Filament\Analysis\Widgets;

use App\Filament\Analysis\Concerns\UsesMarginPeriod;
use App\Filament\Widgets\Concerns\CategoryDoughnut;
use MortalKiller\FilamentAppearance\Widgets\BreakdownChartWidget;

/**
 * Part-to-whole of what was invoiced: direct costs, overhead (estrutura) and what is left.
 */
class MarginsBreakdownChart extends BreakdownChartWidget
{
    use CategoryDoughnut;
    use UsesMarginPeriod;

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): string
    {
        return __('Where invoiced revenue goes');
    }

    public function getDescription(): string
    {
        return $this->period()->label().'.';
    }

    protected function amounts(): array
    {
        $totals = $this->report()->totals();

        return [
            __('Direct costs') => $totals['directCosts'],
            __('Overhead costs') => $totals['overhead'],
            __('Result') => $totals['result'],
        ];
    }
}
