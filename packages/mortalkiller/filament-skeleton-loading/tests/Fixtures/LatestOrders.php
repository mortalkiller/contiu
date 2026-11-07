<?php

declare(strict_types=1);

namespace MortalKiller\FilamentSkeletonLoading\Tests\Fixtures;

use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

final class LatestOrders extends TableWidget
{
    use HasSkeletonPlaceholder;

    protected ?int $skeletonRows = 3;

    public function table(Table $table): Table
    {
        return $table->records(fn (): array => []);
    }
}
