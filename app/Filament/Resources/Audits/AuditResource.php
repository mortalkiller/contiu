<?php

namespace App\Filament\Resources\Audits;

use App\Filament\Resources\Audits\Pages\ListAudits;
use App\Filament\Resources\Audits\Pages\ViewAudit;
use App\Models\User;
use BackedEnum;
use Tapp\FilamentAuditing\Filament\Resources\Audits\AuditResource as TappAuditResource;
use ToneGabes\Filament\Icons\Enums\Phosphor;
use UnitEnum;

/**
 * tapp's global history of every change. Audits of all accounts live in one table, so this screen
 * is for super admins only; owners see a record's history in its own "Histórico" tab, which is
 * scoped through the record. (tapp's own tenancy switch stays off: it would replace
 * audit.implementation and lose App\Models\Audit's account stamping.)
 */
class AuditResource extends TappAuditResource
{
    protected static string|BackedEnum|null $navigationIcon = Phosphor::ClockCounterClockwise;

    protected static string|UnitEnum|null $navigationGroup = 'Definições';

    protected static ?int $navigationSort = 20;

    public static function isScopedToTenant(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return User::loggedInIsSuperAdmin();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAudits::route('/'),
            'view' => ViewAudit::route('/{record}'),
        ];
    }
}
