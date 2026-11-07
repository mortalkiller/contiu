<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Enums\ExpenseKind;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Expense;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListExpenses extends ListRecords
{
    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nova despesa'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Todas'),
            'unpaid' => Tab::make('Por pagar')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_paid', false))
                ->badge(fn () => Expense::query()->where('is_paid', false)->count() ?: null)
                ->badgeColor('warning'),
            'paid' => Tab::make('Pagas')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_paid', true)),
            'overhead' => Tab::make('Estrutura')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('kind', ExpenseKind::Overhead)),
            'direct' => Tab::make('Diretas')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('kind', ExpenseKind::Direct)),
        ];
    }
}
