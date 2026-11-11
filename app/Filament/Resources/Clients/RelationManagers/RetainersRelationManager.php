<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Filament\Resources\Retainers\RetainerResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

class RetainersRelationManager extends RelationManager
{
    use HasSkeletonPlaceholder;

    protected static string $relationship = 'retainers';

    protected static ?string $relatedResource = RetainerResource::class;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Retainers');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return ((string) $ownerRecord->retainers()->count()) ?: null;
    }

    public function table(Table $table): Table
    {
        $table = RetainerResource::table($table)
            // The tab already says what the list is, and the export belongs to the full screen.
            ->heading(null)
            ->headerActions([]);

        // Every row is this client's: its own column would only repeat the name in the header.
        $table->getColumn('client.name')?->hidden();

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['client', 'receivables' => fn ($query) => $query->withOutstandingBalance(), 'receivables.payments', 'expenses']));
    }
}
