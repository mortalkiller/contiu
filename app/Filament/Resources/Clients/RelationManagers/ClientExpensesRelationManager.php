<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Filament\Resources\Expenses\ExpenseResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

/**
 * "Expenses" on the client page: what was spent on the client's projects and retainers, in the
 * same table as the expenses screen.
 */
class ClientExpensesRelationManager extends RelationManager
{
    use HasSkeletonPlaceholder;

    /** A query on the client (Client::expensesQuery()), not an Eloquent relation. */
    protected static string $relationship = 'expensesQuery';

    protected static ?string $relatedResource = ExpenseResource::class;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Expenses');
    }

    public function table(Table $table): Table
    {
        return ExpenseResource::table($table)
            // The tab already says what the list is, and the export belongs to the full screen.
            ->heading(null)
            ->headerActions([])
            // The table reads the client's query itself: there is no Eloquent relation to derive it from.
            ->query(fn (): Builder => $this->getOwnerRecord()->expensesQuery())
            ->emptyStateHeading(__('No expenses on this client\'s projects'));
    }
}
