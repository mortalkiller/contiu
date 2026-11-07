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
                TextColumn::make('expenses_count')
                    ->label('Despesas')
                    ->numeric()
                    ->alignCenter(),
                TextColumn::make('total_spent')
                    ->label('Total gasto')
                    ->state(fn (Supplier $record) => $record->expenses->sum(fn (Expense $expense) => $expense->total))
                    ->money('EUR')
                    ->alignEnd(),
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
