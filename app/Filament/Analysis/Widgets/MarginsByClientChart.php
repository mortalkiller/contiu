<?php

namespace App\Filament\Analysis\Widgets;

use App\Filament\Analysis\Concerns\UsesMarginPeriod;
use App\Filament\Widgets\Concerns\CategoryDoughnut;
use Filament\Widgets\ChartWidget;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

class MarginsByClientChart extends ChartWidget
{
    use CategoryDoughnut;
    use HasSkeletonPlaceholder;
    use UsesMarginPeriod;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '220px';

    public function getHeading(): string
    {
        return 'Faturado por cliente';
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
