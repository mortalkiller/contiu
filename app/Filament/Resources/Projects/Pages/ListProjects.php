<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Enums\ProjectStatus;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Receivable;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListProjects extends ListRecords
{
    protected static string $resource = ProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Novo trabalho'),
        ];
    }

    public function getTabs(): array
    {
        $withOpenReceivables = fn (Builder $query) => $query
            ->where('status', '!=', ProjectStatus::Cancelled)
            ->whereHas('receivables', fn (Builder $query) => $query->whereIn('receivables.id', Receivable::query()->open()->select('id')));

        return [
            'all' => Tab::make('Todos'),
            'active' => Tab::make('Em curso')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [ProjectStatus::Approved, ProjectStatus::InProgress])),
            'outstanding' => Tab::make('Por receber')
                ->modifyQueryUsing($withOpenReceivables)
                ->badge(fn () => $withOpenReceivables(ProjectResource::getModel()::query())->count() ?: null)
                ->badgeColor('warning'),
            'proposals' => Tab::make('Propostas')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ProjectStatus::Proposal)),
            'completed' => Tab::make('Concluídos')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ProjectStatus::Completed)),
            'cancelled' => Tab::make('Cancelados')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ProjectStatus::Cancelled)),
        ];
    }
}
