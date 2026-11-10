<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Filament\Resources\Shared\RelationManagers\PaymentsRelationManager;
use Illuminate\Database\Eloquent\Model;

/** What the project has already been paid, with how many payments there are on the tab. */
class ProjectPaymentsRelationManager extends PaymentsRelationManager
{
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return ((string) $ownerRecord->payments()->distinct()->count('payments.id')) ?: null;
    }
}
