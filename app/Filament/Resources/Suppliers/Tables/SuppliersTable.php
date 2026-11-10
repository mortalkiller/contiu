<?php

namespace App\Filament\Resources\Suppliers\Tables;

use App\Models\Expense;
use App\Models\Supplier;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SuppliersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->weight('medium')
                    ->searchable(),
                TextColumn::make('vat_number')
                    ->label('NIF')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('website')
                    ->label('Site')
                    ->placeholder('—')
                    ->url(fn (Supplier $record) => $record->website, shouldOpenInNewTab: true)
                    ->formatStateUsing(fn (?string $state) => $state ? preg_replace('#^https?://(www\.)?#', '', $state) : null)
                    ->color('primary'),
                TextColumn::make('seat')
                    ->label('Sede')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('usual_category')
                    ->label('Categoria habitual')
                    ->state(fn (Supplier $record): ?string => $record->expenses
                        ->countBy(fn (Expense $expense): string => $expense->category?->name ?? '')
                        ->forget('')
                        ->sortDesc()
                        ->keys()
                        ->first())
                    ->placeholder('—'),
                TextColumn::make('expenses_count')
                    ->label('Despesas')
                    ->numeric()
                    ->alignCenter(),
                TextColumn::make('total_spent')
                    ->label('Total gasto')
                    ->state(fn (Supplier $record) => $record->expenses->sum(fn (Expense $expense) => $expense->total))
                    ->money('EUR', divideBy: 100)
                    ->alignEnd(),
                TextColumn::make('unpaid')
                    ->label('Por pagar')
                    ->state(fn (Supplier $record): ?int => $record->expenses->reject(fn (Expense $expense): bool => (bool) $expense->is_paid)->sum(fn (Expense $expense) => $expense->total) ?: null)
                    ->money('EUR', divideBy: 100)
                    ->placeholder('—')
                    ->color('warning')
                    ->weight('semibold')
                    ->alignEnd(),
                TextColumn::make('last_expense_on')
                    ->label('Última despesa')
                    ->state(fn (Supplier $record) => $record->expenses->max('spent_on'))
                    ->date('d/m/Y')
                    ->placeholder('—'),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    DeleteAction::make()
                        ->visible(fn (Supplier $record) => $record->expenses_count === 0),
                ]),
            ])
            ->emptyStateHeading('Sem fornecedores')
            ->emptyStateDescription('Os fornecedores também podem ser criados diretamente ao registar uma despesa.');
    }
}
