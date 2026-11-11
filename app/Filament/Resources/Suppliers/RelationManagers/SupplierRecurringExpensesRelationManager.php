<?php

namespace App\Filament\Resources\Suppliers\RelationManagers;

use App\Models\RecurringExpense;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

/**
 * The costs that repeat with a supplier: what each one is, how often and how much, what it adds
 * up to in a year and when the next expense will be created.
 */
class SupplierRecurringExpensesRelationManager extends RelationManager
{
    use HasSkeletonPlaceholder;

    protected static string $relationship = 'recurringExpenses';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Recurring');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return ((string) $ownerRecord->recurringExpenses()->where('is_active', true)->count()) ?: null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('category'))
            ->heading(null)
            ->columns([
                TextColumn::make('description')
                    ->label(__('Description'))
                    ->description(fn (RecurringExpense $record): ?string => $record->category?->name)
                    ->weight(FontWeight::Medium)
                    ->wrap(),
                TextColumn::make('frequency')
                    ->label(__('Schedule')),
                TextColumn::make('total')
                    ->label(__('Amount'))
                    ->money('EUR', divideBy: 100)
                    ->alignEnd(),
                TextColumn::make('yearly_cost')
                    ->label(__('Per year'))
                    ->money('EUR', divideBy: 100)
                    ->alignEnd(),
                TextColumn::make('next_on')
                    ->label(__('Next expense'))
                    ->date()
                    ->placeholder('—'),
                TextColumn::make('is_active')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? __('Active recurring expense') : __('Paused'))
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
            ])
            ->emptyStateHeading(__('No recurring expenses'))
            ->emptyStateDescription(__('Create one for recurring costs such as subscriptions or servers.'));
    }
}
