<?php

namespace App\Filament\Analysis\Widgets;

use App\Filament\Analysis\Concerns\UsesMarginPeriod;
use App\Filament\Widgets\Concerns\CategoryDoughnut;
use MortalKiller\FilamentAppearance\Widgets\BreakdownChartWidget;

class MarginsByClientChart extends BreakdownChartWidget
{
    use CategoryDoughnut;
    use UsesMarginPeriod;

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): string
    {
        return __('Invoiced by client');
    }

    public function getDescription(): string
    {
        return $this->period()->label().'.';
    }

    protected function amounts(): array
    {
        return array_map(fn (array $row) => $row['invoiced'], $this->report()->byClient());
    }
}
