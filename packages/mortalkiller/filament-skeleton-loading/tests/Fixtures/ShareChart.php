<?php

declare(strict_types=1);

namespace MortalKiller\FilamentSkeletonLoading\Tests\Fixtures;

use Filament\Widgets\ChartWidget;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

final class ShareChart extends ChartWidget
{
    use HasSkeletonPlaceholder;

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        return [];
    }
}
