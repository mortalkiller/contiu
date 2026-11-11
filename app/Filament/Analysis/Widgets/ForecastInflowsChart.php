<?php

namespace App\Filament\Analysis\Widgets;

use App\Filament\Analysis\Concerns\ForecastsMonths;
use App\Filament\Widgets\Concerns\CategoryDoughnut;
use MortalKiller\FilamentAppearance\Widgets\BreakdownChartWidget;

class ForecastInflowsChart extends BreakdownChartWidget
{
    use CategoryDoughnut;
    use ForecastsMonths;

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): string
    {
        return __('Expected inflows');
    }

    public function getDescription(): string
    {
        return __('Next :months months, by source.', ['months' => $this->months]);
    }

    protected function amounts(): array
    {
        return $this->forecast()->inflowBreakdown();
    }
}
