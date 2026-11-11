<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Filament\Resources\Shared\RelationManagers\ReceivablesRelationManager;
use Illuminate\Database\Eloquent\Model;

/** The project's instalment plan, as the first tab of its detail page, with how many there are. */
class ProjectReceivablesRelationManager extends ReceivablesRelationManager
{
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Receivables');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return ((string) $ownerRecord->receivables()->count()) ?: null;
    }
}
