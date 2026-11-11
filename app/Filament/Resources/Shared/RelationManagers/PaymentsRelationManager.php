<?php

namespace App\Filament\Resources\Shared\RelationManagers;

use App\Filament\Support\ReconciliationColumns;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

/**
 * Read-only list of what came in for a project or retainer (record new ones with "Registar recebimento").
 */
class PaymentsRelationManager extends RelationManager
{
    use HasSkeletonPlaceholder;

    protected static string $relationship = 'payments';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Payments received');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('receivable'))
            ->defaultSort('paid_on', 'desc')
            ->columns([
                TextColumn::make('paid_on')->label(__('Date'))->date()->sortable(),
                TextColumn::make('receivable.description')->label(__('For')),
                TextColumn::make('method')->label(__('Method'))->badge()->color('gray'),
                TextColumn::make('invoice_number')->label(__('Invoice'))->placeholder('—'),
                TextColumn::make('amount')->label(__('Amount'))->money('EUR', divideBy: 100)->alignEnd()
                    ->summarize(Sum::make()->label(__('Total'))->money('EUR', divideBy: 100)),
                ReconciliationColumns::paymentColumn(),
                TextColumn::make('notes')->label(__('Notes'))->placeholder('—')->limit(40)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                ReconciliationColumns::detailsAction()->iconButton()->tooltip(__('View bank transaction')),
            ])
            ->emptyStateHeading(__('No payments received yet'));
    }
}
