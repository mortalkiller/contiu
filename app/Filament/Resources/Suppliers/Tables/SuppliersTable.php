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
                    ->label(__('Name'))
                    ->weight('medium')
                    ->searchable(),
                TextColumn::make('vat_number')
                    ->label(__('Tax ID'))
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('website')
                    ->label(__('Website'))
                    ->placeholder('—')
                    ->url(fn (Supplier $record) => $record->website, shouldOpenInNewTab: true)
                    ->formatStateUsing(fn (?string $state) => $state ? preg_replace('#^https?://(www\.)?#', '', $state) : null)
                    ->color('primary'),
                TextColumn::make('seat')
                    ->label(__('Location'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('usual_category')
                    ->label(__('Usual category'))
                    ->state(fn (Supplier $record): ?string => $record->expenses
                        ->countBy(fn (Expense $expense): string => $expense->category?->name ?? '')
                        ->forget('')
                        ->sortDesc()
                        ->keys()
                        ->first())
                    ->placeholder('—'),
                TextColumn::make('expenses_count')
                    ->label(__('Expenses'))
                    ->numeric()
                    ->alignCenter(),
                TextColumn::make('total_spent')
                    ->label(__('Total spent'))
                    ->state(fn (Supplier $record) => $record->expenses->sum(fn (Expense $expense) => $expense->total))
                    ->money('EUR', divideBy: 100)
                    ->alignEnd(),
                TextColumn::make('unpaid')
                    ->label(__('To pay'))
                    ->state(fn (Supplier $record): ?int => $record->expenses->reject(fn (Expense $expense): bool => (bool) $expense->is_paid)->sum(fn (Expense $expense) => $expense->total) ?: null)
                    ->money('EUR', divideBy: 100)
                    ->placeholder('—')
                    ->color('warning')
                    ->weight('semibold')
                    ->alignEnd(),
                TextColumn::make('last_expense_on')
                    ->label(__('Last expense'))
                    ->state(fn (Supplier $record) => $record->expenses->max('spent_on'))
                    ->date()
                    ->placeholder('—'),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    DeleteAction::make()
                        ->visible(fn (Supplier $record) => $record->expenses_count === 0),
                ]),
            ])
            ->emptyStateHeading(__('No suppliers'))
            ->emptyStateDescription(__('You can also create suppliers when recording an expense.'));
    }
}
