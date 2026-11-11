<?php

namespace App\Filament\Resources\Retainers\Schemas;

use App\Enums\CategoryType;
use App\Enums\Frequency;
use App\Enums\Language;
use App\Filament\Support\FinanceFields;
use App\Filament\Support\QuickCreate;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

class RetainerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->defaultDateDisplayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
            ->defaultNumberLocale(fn (): string => app()->getLocale())
            ->columns(3)
            ->components([
                Section::make(__('Retainer'))
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextInput::make('code')
                            ->label(__('Code'))
                            ->placeholder('MINFRA')
                            ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('account_id', CurrentAccount::id())) // codes repeat across accounts
                            ->required(),
                        Toggle::make('is_active')
                            ->label(__('Active retainer'))
                            ->default(true)
                            ->inline(false),
                        Toggle::make('tax_exempt')
                            ->label(__('Tax-exempt retainer'))
                            ->helperText(__('Payments for this retainer are excluded from tax estimates.'))
                            ->default(fn () => app(FinanceSettings::class)->paymentsExemptByDefault())
                            ->inline(false),
                        FinanceFields::clientSelect(),
                        TextInput::make('business_line')
                            ->label(__('Business line'))
                            ->datalist(fn () => FinanceFields::businessLines()),
                        TextInput::make('title')
                            ->label(__('Title'))
                            ->placeholder(__('Website infrastructure'))
                            ->columnSpanFull()
                            ->required(),
                        QuickCreate::category(Select::make('category_id')
                            ->label(__('Category'))
                            ->relationship('category', 'name', fn (Builder $query) => $query->income())
                            ->native(false), CategoryType::Income),
                    ]),

                Section::make(__('Amount per period'))
                    ->columnSpan(1)
                    ->schema([
                        FinanceFields::netAmount(),
                        FinanceFields::vatRate(),
                        FinanceFields::totalPreview(),
                    ]),

                Section::make(__('Schedule'))
                    ->description(__('The app will create one receivable per period. (Mock: automatic generation will be implemented later.)'))
                    ->columnSpanFull()
                    ->columns(4)
                    ->schema([
                        ToggleButtons::make('frequency')
                            ->label(__('Billing frequency'))
                            ->options(Frequency::class)
                            ->default(Frequency::Monthly)
                            ->inline()
                            ->grouped()
                            ->required(),
                        TextInput::make('due_day')
                            ->label(__('Due day'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(28)
                            ->default(1)
                            ->required(),
                        DatePicker::make('starts_on')
                            ->label(__('Start'))
                            ->native(false)
                            ->displayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
                            ->default(today()->startOfMonth())
                            ->required(),
                        DatePicker::make('ends_on')
                            ->label(__('End'))
                            ->native(false)
                            ->displayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
                            ->placeholder(__('No end date')),
                    ]),

                Section::make(__('Notes'))
                    ->columnSpanFull()
                    ->collapsed()
                    ->schema([
                        Textarea::make('notes')->hiddenLabel()->rows(3),
                    ]),
            ]);
    }
}
