<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Filament\Resources\Projects\ProjectResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class ProjectsRelationManager extends RelationManager
{
    protected static string $relationship = 'projects';

    protected static ?string $relatedResource = ProjectResource::class;

    protected static ?string $title = 'Trabalhos';

    public function table(Table $table): Table
    {
        return ProjectResource::table($table)
            ->modifyQueryUsing(fn ($query) => $query->with(['client', 'receivables.payments', 'expenses']));
    }
}
