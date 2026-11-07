<?php

namespace App\Filament\Resources\RecurringExpenses\Tables;

use App\Filament\Resources\Expenses\Schemas\ExpenseForm;
use App\Models\RecurringExpense;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class RecurringExpensesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('description')
            ->columns([
                TextColumn::make('supplier.name')
                    ->label('Fornecedor')
                    ->searchable(),
                TextColumn::make('description')
                    ->label('Descrição')
                    ->description(fn (RecurringExpense $record) => $record->category?->name)
                    ->searchable(),
                TextColumn::make('kind')
                    ->label('Tipo')
                    ->badge(),
                TextColumn::make('frequency')
                    ->label('Periodicidade'),
                TextColumn::make('day')
                    ->label('Dia')
                    ->formatStateUsing(fn ($state) => 'Dia '.$state),
                TextColumn::make('total')
                    ->label('Valor')
                    ->money('EUR', divideBy: 100)
                    ->alignEnd(),
                TextColumn::make('yearly_cost')
                    ->label('Por ano')
                    ->money('EUR', divideBy: 100)
                    ->alignEnd()
                    ->summarize(Summarizer::make()
                        ->label('Total anual')
                        ->money('EUR', divideBy: 100)
                        ->using(fn ($query) => RecurringExpense::query()->whereIn('id', $query->pluck('id'))->where('is_active', true)->get()->sum(fn (RecurringExpense $row) => $row->yearly_cost))),
                TextColumn::make('next_on')
                    ->label('Próxima')
                    ->date(),
                TextColumn::make('expenses_count')
                    ->label('Lançadas')
                    ->numeric()
                    ->alignCenter(),
                ToggleColumn::make('is_active')
                    ->label('Ativa'),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()
                        ->modalWidth('5xl')
                        ->mutateDataUsing(fn (array $data) => ExpenseForm::normalize($data)),
                    DeleteAction::make(),
                ]),
            ])
            ->emptyStateHeading('Sem despesas recorrentes')
            ->emptyStateDescription('Cria uma para custos que se repetem, como subscrições ou servidores.');
    }
}
