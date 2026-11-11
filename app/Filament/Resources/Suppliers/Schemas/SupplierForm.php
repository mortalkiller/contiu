<?php

namespace App\Filament\Resources\Suppliers\Schemas;

use App\Filament\Support\FinanceFields;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class SupplierForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->columnSpanFull(),
                TextInput::make('vat_number')
                    ->label(__('Tax ID')),
                TextInput::make('website')
                    ->label(__('Website'))
                    ->url()
                    ->placeholder('https://'),
                FinanceFields::seat()
                    ->helperText(__('VAT on services purchased from businesses based outside Portugal is accounted for under the reverse charge.')),
                Textarea::make('notes')
                    ->label(__('Notes'))
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }
}
