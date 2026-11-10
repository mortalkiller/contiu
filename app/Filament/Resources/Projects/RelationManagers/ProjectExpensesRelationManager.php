<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Filament\Resources\Shared\RelationManagers\ExpensesRelationManager;
use Illuminate\Database\Eloquent\Model;

/** The project's direct costs, with how many there are on the tab. */
class ProjectExpensesRelationManager extends ExpensesRelationManager
{
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return ((string) $ownerRecord->expenses()->count()) ?: null;
    }
}
