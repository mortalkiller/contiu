<?php

namespace App\Filament\Resources\Shared\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only list of what came in for a project or retainer (record new ones with "Registar recebimento").
 */
class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Recebimentos';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('receivable'))
            ->defaultSort('paid_on', 'desc')
            ->columns([
                TextColumn::make('paid_on')->label('Data')->date()->sortable(),
                TextColumn::make('receivable.description')->label('Referente a'),
                TextColumn::make('method')->label('Método')->badge()->color('gray'),
                TextColumn::make('invoice_number')->label('Fatura')->placeholder('—'),
                TextColumn::make('amount')->label('Valor')->money('EUR', divideBy: 100)->alignEnd()
                    ->summarize(Sum::make()->label('Total')->money('EUR', divideBy: 100)),
                TextColumn::make('notes')->label('Notas')->placeholder('—')->limit(40)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->emptyStateHeading('Ainda sem recebimentos');
    }
}
