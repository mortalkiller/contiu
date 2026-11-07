<?php

namespace App\Filament\Analysis\Widgets;

use App\Filament\Analysis\Concerns\UsesMarginPeriod;
use App\Filament\Widgets\Concerns\CategoryDoughnut;
use Filament\Widgets\ChartWidget;

class MarginsByLineChart extends ChartWidget
{
    use CategoryDoughnut;
    use UsesMarginPeriod;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '220px';

    public function getHeading(): string
    {
        return 'Faturado por linha de negócio';
    }

    public function getDescription(): string
    {
        return $this->period()->label().'.';
    }

    protected function amounts(): array
    {
        return array_map(fn (array $row) => $row['invoiced'], $this->report()->byBusinessLine());
    }
}
