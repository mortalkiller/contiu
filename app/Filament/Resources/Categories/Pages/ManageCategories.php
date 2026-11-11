<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Enums\CategoryType;
use App\Filament\Resources\Categories\CategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ManageCategories extends ManageRecords
{
    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('New category'))
                ->fillForm(fn () => ['type' => $this->activeTab === 'income' ? CategoryType::Income : CategoryType::Expense, 'sort' => 99]),
        ];
    }

    public function getTabs(): array
    {
        return [
            'expense' => Tab::make(__('Expenses'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('type', CategoryType::Expense)),
            'income' => Tab::make(__('Revenue'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('type', CategoryType::Income)),
        ];
    }
}
