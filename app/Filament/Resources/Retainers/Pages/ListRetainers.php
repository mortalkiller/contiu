<?php

namespace App\Filament\Resources\Retainers\Pages;

use App\Filament\Resources\Retainers\RetainerResource;
use App\Filament\Resources\Retainers\Widgets\RetainersAhead;
use App\Filament\Resources\Retainers\Widgets\RetainersSummary;
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

    protected function getHeaderWidgets(): array
    {
        return [RetainersSummary::class];
    }

    protected function getFooterWidgets(): array
    {
        return [RetainersAhead::class];
    }

    public function getTabs(): array
    {
        $count = fn (bool $active): ?int => RetainerResource::getModel()::query()->where('is_active', $active)->count() ?: null;

        return [
            'active' => Tab::make('Ativas')->badge($count(true))->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', true)),
            'ended' => Tab::make('Terminadas')->badge($count(false))->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', false)),
            'all' => Tab::make('Todas'),
        ];
    }
}
