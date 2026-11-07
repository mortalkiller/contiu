<?php

namespace App\Filament\Resources\RecurringExpenses\Schemas;

use App\Enums\Frequency;
use App\Filament\Resources\Expenses\Schemas\ExpenseForm;
use App\Filament\Support\FinanceFields;
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
            ->columns(3)
            ->components([
                Section::make('Despesa')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        FinanceFields::supplierSelect(),
                        Select::make('category_id')
                            ->label('Categoria')
                            ->relationship('category', 'name', fn (Builder $query) => $query->expense())
                            ->native(false),
                        TextInput::make('description')
                            ->label('Descrição')
                            ->required()
                            ->columnSpanFull(),
                    ]),

                Section::make('Valor')
                    ->columnSpan(1)
                    ->schema([
                        FinanceFields::netAmount(),
                        FinanceFields::vatRate(),
                        FinanceFields::totalPreview(),
                    ]),

                Section::make('Repetição')
                    ->columnSpanFull()
                    ->columns(4)
                    ->schema([
                        ToggleButtons::make('frequency')
                            ->label('Periodicidade')
                            ->options(Frequency::class)
                            ->default(Frequency::Monthly)
                            ->inline()
                            ->required()
                            ->columnSpan(2),
                        TextInput::make('day')
                            ->label('Dia do mês')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(31)
                            ->default(1)
                            ->required(),
                        Toggle::make('is_active')
                            ->label('Ativa')
                            ->default(true)
                            ->inline(false),
                        DatePicker::make('starts_on')
                            ->label('Começa em')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->default(today())
                            ->required()
                            ->columnSpan(2),
                        DatePicker::make('ends_on')
                            ->label('Termina em')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->placeholder('Sem fim')
                            ->columnSpan(2),
                    ]),

                ...ExpenseForm::classificationAndPayment(withPaymentStatus: false),
            ]);
    }
}
