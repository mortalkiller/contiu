<?php

namespace App\Filament\Resources\Shared\RelationManagers;

use App\Enums\ExpenseKind;
use App\Filament\Support\ReconciliationColumns;
use App\Support\Money;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

/**
 * Direct costs of a project or retainer; they reduce its margin.
 */
class ExpensesRelationManager extends RelationManager
{
    use HasSkeletonPlaceholder;

    protected static string $relationship = 'expenses';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Direct costs');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('supplier')->where('kind', ExpenseKind::Direct))
            ->defaultSort('spent_on', 'desc')
            ->columns([
                TextColumn::make('spent_on')->label(__('Date'))->date()->sortable(),
                TextColumn::make('supplier.name')->label(__('Supplier')),
                TextColumn::make('description')->label(__('Description')),
                TextColumn::make('total')->label(__('Total'))->money('EUR', divideBy: 100)->alignEnd()
                    ->summarize(Summarizer::make()->label(__('Total'))->money('EUR', divideBy: 100)
                        ->using(fn ($query) => $query->get()->sum(fn ($row) => (int) $row->net_amount + Money::vat((int) $row->net_amount, (string) $row->vat_rate)))),
                IconColumn::make('is_paid')->label(__('Expense paid'))->boolean(),
                ReconciliationColumns::expenseColumn(),
            ])
            ->recordActions([
                ReconciliationColumns::detailsAction()->iconButton()->tooltip(__('View bank transaction')),
            ])
            ->emptyStateHeading(__('No direct costs'))
            ->emptyStateDescription(__('Link expenses to this project from the Expenses page.'));
    }
}
