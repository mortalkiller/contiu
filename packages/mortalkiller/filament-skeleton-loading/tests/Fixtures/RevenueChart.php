<?php

declare(strict_types=1);

namespace MortalKiller\FilamentSkeletonLoading\Tests\Fixtures;

use Filament\Widgets\ChartWidget;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

final class RevenueChart extends ChartWidget
{
    use HasSkeletonPlaceholder;

    protected ?string $heading = 'Revenue';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        return [];
    }
}
