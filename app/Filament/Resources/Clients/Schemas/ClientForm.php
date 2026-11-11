<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\Enums\FiscalProfilePartyType;
use App\Enums\FiscalProfileViesStatus;
use App\Enums\Language;
use App\Enums\PortugueseVatRegion;
use App\Filament\Support\FinanceFields;
use App\Filament\Support\FormPart;
use App\Models\Client;
use App\Models\ClientAddress;
use App\Models\Country;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class ClientForm
{
    /**
     * As in the mock ("Novo cliente"): who the client is, their tax details, their address and
     * private notes, one block each; cards on the edit page, blocks under a hairline in a modal.
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            FormPart::make()
                ->columnSpanFull()
                ->columns(['default' => 1, 'sm' => 2])
                ->schema([
                    TextInput::make('name')->label(__('Name'))->required()->columnSpanFull(),
                    TextInput::make('email')->label(__('Email'))->email(),
                    TextInput::make('phone')->label(__('Phone'))->tel()->placeholder('+351 …'),
                    Select::make('language')->label(__('Language'))->options(Language::options())
                        ->helperText(__('The language of the documents and emails you send them.'))
                        ->default(Language::PortuguesePortugal->value)->required()->in(array_keys(Language::options())),
                ]),
            FormPart::make(__('Tax details'))
                ->columnSpanFull()
                ->columns(['default' => 1, 'sm' => 2])
                ->schema([
                    Select::make('fiscal_country_code')->label(__('Tax country'))
                        ->options(fn (?Client $record): array => self::fiscalCountries($record))
                        ->getOptionLabelUsing(fn (string $value, ?Client $record): ?string => self::fiscalCountries($record)[strtoupper($value)] ?? null)
                        ->searchable(),
                    Select::make('fiscal_party_type')->label(__('Entity type'))
                        ->options(FiscalProfilePartyType::class),
                    TextInput::make('vat_number')->label(__('Tax ID / VAT number'))->maxLength(255),
                    Select::make('vat_region')->label(__('VAT region'))
                        ->helperText(__('Of the establishment that receives the invoice.'))
                        ->options(fn (): array => collect(PortugueseVatRegion::cases())
                            ->mapWithKeys(fn (PortugueseVatRegion $region): array => [$region->value => $region->label()])
                            ->all()),
                    FinanceFields::seat(__('Where is the client based?'))
                        ->helperText(__('The company’s registered office or the person’s address.')),
                    FinanceFields::isBusiness()
                        ->inline(false),
                    TextEntry::make('vies_status')->label(__('VIES verification'))
                        ->state(fn (?Client $record): FiscalProfileViesStatus => $record?->vat_validation_status ?? FiscalProfileViesStatus::Pending)
                        ->visible(fn (?Client $record): bool => $record !== null)
                        ->badge(),
                    TextEntry::make('vies_recorded_at')->label(__('Result recorded on'))
                        ->state(fn (?Client $record) => $record?->vat_validated_at)
                        ->visible(fn (?Client $record): bool => $record?->vat_validated_at !== null)
                        ->dateTime(),
                ]),
            self::structuredAddress(),
            FormPart::make()
                ->columnSpanFull()
                ->schema([
                    Textarea::make('address')->label(__('Legacy address'))->rows(2)
                        ->visible(fn (?Client $record): bool => filled($record?->getRawOriginal('address'))),
                    Textarea::make('notes')->label(__('Notes'))->rows(2)
                        ->placeholder(__('Only for you. It does not appear on documents.')),
                ]),
        ]);
    }

    private static function structuredAddress(): Section
    {
        return FormPart::make(__('Address'))
            ->relationship('structuredAddress', condition: fn (?array $state): bool => filled($state['country_code'] ?? null))
            ->columnSpanFull()
            ->columns(2)
            ->schema([
                Select::make('country_code')->label(__('Country'))
                    ->options(fn (): array => Country::options())
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('district_code', null);
                        $set('municipality_code', null);
                        $set('parish_code', null);
                        $set('district', null);
                        $set('parish', null);
                    }),
                TextInput::make('street')->label(__('Street address'))->maxLength(255)
                    ->visible(fn (Get $get): bool => filled($get('country_code')))->columnSpanFull(),
                TextInput::make('postal_code')->label(__('Postal code'))->maxLength(32)
                    ->visible(fn (Get $get): bool => filled($get('country_code'))),
                TextInput::make('locality')->label(__('Postal locality'))->maxLength(255)
                    ->visible(fn (Get $get): bool => filled($get('country_code'))),
                self::administrativeArea('district', __('District or island'))
                    ->afterStateUpdated(function (Set $set): void {
                        $set('municipality_code', null);
                        $set('parish_code', null);
                    }),
                self::administrativeArea('municipality', __('Municipality'), 'district_code')
                    ->afterStateUpdated(fn (Set $set) => $set('parish_code', null)),
                self::administrativeArea('parish', __('Parish'), 'municipality_code'),
                TextInput::make('district')->label(__('District or region'))->maxLength(255)
                    ->visible(fn (Get $get): bool => filled($get('country_code')) && $get('country_code') !== 'PT'),
                TextInput::make('parish')->label(__('Parish'))->maxLength(255)
                    ->visible(fn (Get $get): bool => filled($get('country_code')) && $get('country_code') !== 'PT'),
            ]);
    }

    private static function administrativeArea(string $kind, string $label, ?string $parent = null): Select
    {
        return Select::make($kind.'_code')->label($label)
            ->visible(fn (Get $get): bool => $get('country_code') === 'PT')
            ->options(fn (Get $get): array => ClientAddress::administrativeAreas($kind, $parent === null ? null : $get($parent))
                ->orderBy('name')->pluck('name', 'code')->all())
            ->searchable()
            ->live();
    }

    /** @return array<string, string> */
    private static function fiscalCountries(?Client $record): array
    {
        $countries = Country::options();
        $savedCode = $record?->fiscal_country_code;

        if ($savedCode !== null && ! isset($countries[$savedCode])) {
            $countries[$savedCode] = $savedCode;
        }

        return $countries;
    }
}
