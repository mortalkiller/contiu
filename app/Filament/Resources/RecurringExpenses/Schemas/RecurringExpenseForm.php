<?php

namespace App\Filament\Resources\RecurringExpenses\Schemas;

use App\Enums\CategoryType;
use App\Enums\Frequency;
use App\Enums\Language;
use App\Filament\Resources\Expenses\Schemas\ExpenseForm;
use App\Filament\Support\FinanceFields;
use App\Filament\Support\QuickCreate;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class RecurringExpenseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->defaultDateDisplayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
            ->defaultNumberLocale(fn (): string => app()->getLocale())
            ->columns(3)
            ->components([
                Section::make(__('Expense'))
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        FinanceFields::supplierSelect(),
                        QuickCreate::category(Select::make('category_id')
                            ->label(__('Category'))
                            ->relationship('category', 'name', fn (Builder $query) => $query->expense())
                            ->native(false), CategoryType::Expense),
                        TextInput::make('description')
                            ->label(__('Description'))
                            ->required()
                            ->columnSpanFull(),
                    ]),

                Section::make(__('Amount'))
                    ->columnSpan(1)
                    ->schema([
                        FinanceFields::netAmount(),
                        FinanceFields::vatRate(),
                        FinanceFields::totalPreview(),
                    ]),

                Section::make(__('Repeat'))
                    ->columnSpanFull()
                    ->columns(4)
                    ->schema([
                        ToggleButtons::make('frequency')
                            ->label(__('Schedule'))
                            ->options(Frequency::class)
                            ->default(Frequency::Monthly)
                            ->inline()
                            ->required()
                            ->columnSpan(2),
                        TextInput::make('day')
                            ->label(__('Day of the month'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(31)
                            ->default(1)
                            ->required(),
                        Toggle::make('is_active')
                            ->label(__('Active recurring expense'))
                            ->default(true)
                            ->inline(false),
                        DatePicker::make('starts_on')
                            ->label(__('Starts on'))
                            ->native(false)
                            ->displayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
                            ->default(today())
                            ->required()
                            ->columnSpan(2),
                        DatePicker::make('ends_on')
                            ->label(__('Ends on'))
                            ->native(false)
                            ->displayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
                            ->placeholder(__('No end date'))
                            ->columnSpan(2),
                    ]),

                ...ExpenseForm::classificationAndPayment(withPaymentStatus: false),
            ]);
    }
}
