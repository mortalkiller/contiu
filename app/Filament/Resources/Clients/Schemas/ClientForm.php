<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\Filament\Support\FinanceFields;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ClientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    TextInput::make('name')->label('Nome')->required()->columnSpanFull(),
                    TextInput::make('vat_number')->label('NIF'),
                    TextInput::make('email')->label('Email')->email(),
                    TextInput::make('phone')->label('Telefone')->tel(),
                    FinanceFields::seat('Onde fica o cliente?')
                        ->helperText('A sede da empresa ou a morada da pessoa.'),
                    FinanceFields::isBusiness()
                        ->inline(false),
                    Textarea::make('address')->label('Morada')->rows(2)->columnSpanFull(),
                    Textarea::make('notes')->label('Notas')->rows(3)->columnSpanFull(),
                ]),
        ]);
    }
}
