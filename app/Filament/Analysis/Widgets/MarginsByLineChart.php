<?php

namespace App\Filament\Analysis\Widgets;

use App\Filament\Analysis\Concerns\UsesMarginPeriod;
use App\Filament\Widgets\Concerns\CategoryDoughnut;
use MortalKiller\FilamentAppearance\Widgets\BreakdownChartWidget;

class MarginsByLineChart extends BreakdownChartWidget
{
    use CategoryDoughnut;
    use UsesMarginPeriod;

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): string
    {
        return __('Invoiced by business line');
    }

    public function getDescription(): string
    {
        return $this->period()->label().'.';
    }

    protected function amounts(): array
    {
        $amounts = [];

        foreach ($this->report()->byBusinessLine() as $row) {
            $label = $row['label'];
            $amounts[$label] = ($amounts[$label] ?? 0) + $row['invoiced'];
        }

        return $amounts;
    }
}
