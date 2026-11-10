<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\Enums\FiscalProfilePartyType;
use App\Enums\FiscalProfileViesStatus;
use App\Filament\Support\FinanceFields;
use App\Models\Client;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
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
                    TextInput::make('email')->label('Email')->email(),
                    TextInput::make('phone')->label('Telefone')->tel(),
                    FinanceFields::seat('Onde fica o cliente?')
                        ->helperText('A sede da empresa ou a morada da pessoa.'),
                    FinanceFields::isBusiness()
                        ->inline(false),
                    Textarea::make('address')->label('Morada')->rows(2)->columnSpanFull(),
                    Textarea::make('notes')->label('Notas')->rows(3)->columnSpanFull(),
                ]),
            Section::make('Dados fiscais')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    TextInput::make('fiscal_country_code')->label('País fiscal (código ISO)')
                        ->placeholder('PT')->regex('/\A[A-Za-z]{2}\z/'),
                    Select::make('fiscal_party_type')->label('Tipo de entidade')
                        ->options(FiscalProfilePartyType::class),
                    TextInput::make('vat_number')->label('NIF / Número de IVA')->maxLength(255)->columnSpanFull(),
                    TextEntry::make('vies_status')->label('Verificação no VIES')
                        ->state(fn (?Client $record): FiscalProfileViesStatus => $record?->vat_validation_status ?? FiscalProfileViesStatus::Pending)
                        ->visible(fn (?Client $record): bool => $record !== null)
                        ->badge(),
                    TextEntry::make('vies_recorded_at')->label('Resultado registado em')
                        ->state(fn (?Client $record) => $record?->vat_validated_at)
                        ->visible(fn (?Client $record): bool => $record?->vat_validated_at !== null)
                        ->dateTime(),
                ]),
        ]);
    }
}
