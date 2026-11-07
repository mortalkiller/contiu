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
                ->label('Nova categoria')
                ->fillForm(fn () => ['type' => $this->activeTab === 'income' ? CategoryType::Income : CategoryType::Expense, 'sort' => 99]),
        ];
    }

    public function getTabs(): array
    {
        return [
            'expense' => Tab::make('Despesas')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('type', CategoryType::Expense)),
            'income' => Tab::make('Receitas')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('type', CategoryType::Income)),
        ];
    }
}
