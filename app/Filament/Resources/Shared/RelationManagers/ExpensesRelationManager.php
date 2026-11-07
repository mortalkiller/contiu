<?php

namespace App\Filament\Resources\Shared\RelationManagers;

use App\Enums\ExpenseKind;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

/**
 * Direct costs of a project or retainer; they reduce its margin.
 */
class ExpensesRelationManager extends RelationManager
{
    use HasSkeletonPlaceholder;

    protected static string $relationship = 'expenses';

    protected static ?string $title = 'Custos diretos';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('supplier')->where('kind', ExpenseKind::Direct))
            ->defaultSort('spent_on', 'desc')
            ->columns([
                TextColumn::make('spent_on')->label('Data')->date()->sortable(),
                TextColumn::make('supplier.name')->label('Fornecedor'),
                TextColumn::make('description')->label('Descrição'),
                TextColumn::make('total')->label('Total')->money('EUR', divideBy: 100)->alignEnd()
                    ->summarize(Summarizer::make()->label('Total')->money('EUR', divideBy: 100)
                        ->using(fn ($query) => $query->get()->sum(fn ($row) => round((float) $row->net_amount * (1 + (float) $row->vat_rate), 2)))),
                IconColumn::make('is_paid')->label('Paga')->boolean(),
            ])
            ->emptyStateHeading('Sem custos diretos')
            ->emptyStateDescription('Associa despesas a este trabalho no ecrã de Despesas.');
    }
}
