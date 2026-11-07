<?php

declare(strict_types=1);

namespace MortalKiller\FilamentSkeletonLoading\Tests\Fixtures;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

final class ItemsRelationManager extends RelationManager
{
    use HasSkeletonPlaceholder;

    protected static string $relationship = 'items';

    public function table(Table $table): Table
    {
        return $table;
    }
}
