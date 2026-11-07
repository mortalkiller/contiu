<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Filament\Resources\Retainers\RetainerResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

class RetainersRelationManager extends RelationManager
{
    use HasSkeletonPlaceholder;

    protected static string $relationship = 'retainers';

    protected static ?string $relatedResource = RetainerResource::class;

    protected static ?string $title = 'Avenças';

    public function table(Table $table): Table
    {
        return RetainerResource::table($table)
            ->modifyQueryUsing(fn ($query) => $query->with(['client', 'receivables.payments', 'expenses']));
    }
}
