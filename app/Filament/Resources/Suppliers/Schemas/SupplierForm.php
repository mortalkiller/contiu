<?php

namespace App\Filament\Resources\Suppliers\Schemas;

use App\Filament\Support\FinanceFields;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * As in the mock ("Novo fornecedor"): name, tax number and website, where the company is seated
 * (it decides how the VAT of what is bought from it is treated) and private notes.
 */
class SupplierForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'sm' => 2])
            ->components([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->columnSpanFull(),
                TextInput::make('vat_number')
                    ->label(__('Tax ID'))
                    ->placeholder(__('E.g. 509999966 or IE1234567X')),
                TextInput::make('website')
                    ->label(__('Website'))
                    ->url()
                    ->placeholder('https://'),
                FinanceFields::seatChoice(__('Seat'))
                    ->helperText(__('VAT on services purchased from businesses based outside Portugal is accounted for under the reverse charge.'))
                    ->columnSpanFull(),
                Textarea::make('notes')
                    ->label(__('Notes'))
                    ->placeholder(__('Optional'))
                    ->rows(2)
                    ->columnSpanFull(),
            ]);
    }
}
