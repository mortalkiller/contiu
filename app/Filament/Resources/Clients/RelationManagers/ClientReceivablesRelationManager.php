<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Filament\Resources\Receivables\ReceivableResource;
use App\Models\Client;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

/**
 * "To receive" on the client page: what the client still owes, across its projects, retainers and
 * direct sales, in the same table as the receivables screen. The tab counts those amounts.
 */
class ClientReceivablesRelationManager extends RelationManager
{
    use HasSkeletonPlaceholder;

    /** A query on the client (Client::receivablesQuery()), not an Eloquent relation. */
    protected static string $relationship = 'receivablesQuery';

    protected static ?string $relatedResource = ReceivableResource::class;

    protected static ?string $badgeColor = 'warning';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('To receive');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $open = $ownerRecord instanceof Client ? $ownerRecord->receivablesQuery()->open()->count() : 0;

        return $open > 0 ? (string) $open : null;
    }

    public function table(Table $table): Table
    {
        $table = ReceivableResource::table($table)
            // The tab already says what the list is, and the export belongs to the full screen.
            ->heading(null)
            ->headerActions([]);

        // Every row is this client's: its own column would only repeat the name in the header.
        $table->getColumn('client')?->hidden();

        return $table
            // The table reads the client's query itself: there is no Eloquent relation to derive it from.
            ->query(fn (): Builder => $this->getOwnerRecord()->receivablesQuery())
            ->modifyQueryUsing(fn (Builder $query) => $query->open())
            ->emptyStateHeading(__('Nothing to receive'))
            ->emptyStateDescription(__('Every amount charged to this client is paid.'));
    }
}
