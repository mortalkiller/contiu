<?php

namespace App\Filament\Resources\Retainers\Pages;

use App\Filament\Resources\Retainers\RetainerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListRetainers extends ListRecords
{
    protected static string $resource = RetainerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nova avença'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'active' => Tab::make('Ativas')->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', true)),
            'ended' => Tab::make('Terminadas')->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', false)),
            'all' => Tab::make('Todas'),
        ];
    }
}
