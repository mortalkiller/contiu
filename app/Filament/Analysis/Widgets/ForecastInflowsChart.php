<?php

namespace App\Filament\Analysis\Widgets;

use App\Filament\Analysis\Concerns\ForecastsMonths;
use App\Filament\Widgets\Concerns\CategoryDoughnut;
use Filament\Widgets\ChartWidget;

class ForecastInflowsChart extends ChartWidget
{
    use CategoryDoughnut;
    use ForecastsMonths;

    protected int|string|array $columnSpan = 'full';

    /** Three doughnuts share the side column on the 6-month view, two on the 3-month one. */
    public function getMaxHeight(): ?string
    {
        return $this->months === 6 ? '250px' : '300px';
    }

    public function getHeading(): string
    {
        return 'Entradas previstas';
    }

    public function getDescription(): string
    {
        return 'Próximos '.$this->months.' meses, por origem.';
    }

    protected function amounts(): array
    {
        return $this->forecast()->inflowBreakdown();
    }
}
