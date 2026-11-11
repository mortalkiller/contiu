<?php

namespace App\Filament\Resources\Categories\Schemas;

use App\Enums\CategoryType;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Schema;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                ToggleButtons::make('type')
                    ->label(__('Type'))
                    ->options(CategoryType::class)
                    ->default(CategoryType::Expense)
                    ->inline()
                    ->grouped()
                    ->required(),
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->maxLength(100),
                Hidden::make('sort')
                    ->default(99),
            ]);
    }
}
