<?php

namespace App\Filament\Resources\RecurringExpenses\Schemas;

use App\Enums\CategoryType;
use App\Enums\Frequency;
use App\Enums\Language;
use App\Enums\PaymentMethod;
use App\Filament\Resources\Expenses\Schemas\ExpenseForm;
use App\Filament\Support\FinanceFields;
use App\Filament\Support\FormPart;
use App\Filament\Support\QuickCreate;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class RecurringExpenseForm
{
    /**
     * As in the mock ("Nova despesa recorrente"): one column of blocks in the order the expense
     * is told, the same as a single expense, with how often it repeats in place of when it was paid.
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->defaultDateDisplayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
            ->defaultNumberLocale(fn (): string => app()->getLocale())
            ->columns(1)
            ->components([
                FormPart::make(__('Expense'))
                    ->columns(['default' => 1, 'sm' => 2])
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

                FormPart::make(__('Amount'))
                    ->columns(['default' => 1, 'sm' => 3])
                    ->schema(FinanceFields::amountFields()),

                FormPart::make(__('Repeat'))
                    ->columns(['default' => 1, 'sm' => 4])
                    ->schema([
                        ToggleButtons::make('frequency')
                            ->label(__('Schedule'))
                            ->options(Frequency::class)
                            ->default(Frequency::Monthly)
                            ->inline()
                            ->grouped()
                            ->required()
                            ->columnSpan(['default' => 1, 'sm' => 2]),
                        TextInput::make('day')
                            ->label(__('Day of the month'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(31)
                            ->default(1)
                            ->required(),
                        DatePicker::make('starts_on')
                            ->label(__('Starts on'))
                            ->native(false)
                            ->displayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
                            ->default(today())
                            ->required()
                            ->columnStart(1)
                            ->columnSpan(['default' => 1, 'sm' => 2]),
                        DatePicker::make('ends_on')
                            ->label(__('Ends on'))
                            ->native(false)
                            ->displayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
                            ->placeholder(__('No end date'))
                            ->columnSpan(['default' => 1, 'sm' => 2]),
                        Toggle::make('is_active')
                            ->label(__('Active recurring expense'))
                            ->helperText(__('When off, it creates no expenses until you turn it back on.'))
                            ->default(true)
                            ->columnSpanFull(),
                    ]),

                FormPart::make(__('Classification'))
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema(ExpenseForm::kindFields()),

                FormPart::make(__('Payment'))
                    ->schema([
                        ToggleButtons::make('method')
                            ->label(__('Method'))
                            ->options(PaymentMethod::class)
                            ->inline(),
                    ]),
            ]);
    }
}
