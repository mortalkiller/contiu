<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Filament\Resources\Payments\PaymentResource;
use App\Models\Client;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

/**
 * "Payments received" on the client page: what the client paid, in the same table as the
 * payments screen. The tab counts the payments.
 */
class ClientPaymentsRelationManager extends RelationManager
{
    use HasSkeletonPlaceholder;

    /** A query on the client (Client::paymentsQuery()), not an Eloquent relation. */
    protected static string $relationship = 'paymentsQuery';

    protected static ?string $relatedResource = PaymentResource::class;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Payments received');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $payments = $ownerRecord instanceof Client ? $ownerRecord->paymentsQuery()->count() : 0;

        return $payments > 0 ? (string) $payments : null;
    }

    public function table(Table $table): Table
    {
        $table = PaymentResource::table($table)
            // The tab already says what the list is, and the export belongs to the full screen.
            ->heading(null)
            ->headerActions([]);

        // Every row is this client's: its own column would only repeat the name in the header.
        $table->getColumn('client')?->hidden();

        return $table
            // The table reads the client's query itself: there is no Eloquent relation to derive it from.
            ->query(fn (): Builder => $this->getOwnerRecord()->paymentsQuery())
            ->emptyStateHeading(__('No payments received yet'));
    }
}
