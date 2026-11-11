<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Enums\ExpenseKind;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Widgets\ExpensesSummary;
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
            CreateAction::make()->label(__('New expense')),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [ExpensesSummary::class];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('All expenses')),
            'unpaid' => Tab::make(__('Unpaid'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_paid', false))
                ->badge(fn () => Expense::query()->where('is_paid', false)->count() ?: null)
                ->badgeColor('warning'),
            'paid' => Tab::make(__('Paid expenses'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_paid', true)),
            'overhead' => Tab::make(__('Overhead'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('kind', ExpenseKind::Overhead)),
            'direct' => Tab::make(__('Direct expenses'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('kind', ExpenseKind::Direct)),
        ];
    }
}
