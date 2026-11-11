<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\Enums\FiscalProfilePartyType;
use App\Enums\FiscalProfileViesStatus;
use App\Enums\Language;
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
                    TextInput::make('name')->label(__('Name'))->required()->columnSpanFull(),
                    TextInput::make('email')->label(__('Email'))->email(),
                    TextInput::make('phone')->label(__('Phone'))->tel(),
                    Select::make('language')->label(__('Language'))->options(Language::options())
                        ->default(Language::PortuguesePortugal->value)->required()->in(array_keys(Language::options())),
                    FinanceFields::seat(__('Where is the client based?'))
                        ->helperText(__('The company’s registered office or the person’s address.')),
                    FinanceFields::isBusiness()
                        ->inline(false),
                    Textarea::make('address')->label(__('Address'))->rows(2)->columnSpanFull(),
                    Textarea::make('notes')->label(__('Notes'))->rows(3)->columnSpanFull(),
                ]),
            Section::make(__('Tax details'))
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    TextInput::make('fiscal_country_code')->label(__('Tax country (ISO code)'))
                        ->placeholder('PT')->regex('/\A[A-Za-z]{2}\z/'),
                    Select::make('fiscal_party_type')->label(__('Entity type'))
                        ->options(FiscalProfilePartyType::class),
                    TextInput::make('vat_number')->label(__('Tax ID / VAT number'))->maxLength(255)->columnSpanFull(),
                    TextEntry::make('vies_status')->label(__('VIES verification'))
                        ->state(fn (?Client $record): FiscalProfileViesStatus => $record?->vat_validation_status ?? FiscalProfileViesStatus::Pending)
                        ->visible(fn (?Client $record): bool => $record !== null)
                        ->badge(),
                    TextEntry::make('vies_recorded_at')->label(__('Result recorded on'))
                        ->state(fn (?Client $record) => $record?->vat_validated_at)
                        ->visible(fn (?Client $record): bool => $record?->vat_validated_at !== null)
                        ->dateTime(),
                ]),
        ]);
    }
}
