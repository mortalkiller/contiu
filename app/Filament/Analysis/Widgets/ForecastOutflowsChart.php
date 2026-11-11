<?php

namespace App\Filament\Analysis\Widgets;

use App\Filament\Analysis\Concerns\ForecastsMonths;
use App\Filament\Widgets\Concerns\CategoryDoughnut;
use MortalKiller\FilamentAppearance\Widgets\BreakdownChartWidget;

class ForecastOutflowsChart extends BreakdownChartWidget
{
    use CategoryDoughnut;
    use ForecastsMonths;

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): string
    {
        return __('Expected outflows');
    }

    public function getDescription(): string
    {
        return __('Next :months months, by type.', ['months' => $this->months]);
    }

    protected function amounts(): array
    {
        return $this->forecast()->outflowBreakdown();
    }
}
