<?php

namespace App\Filament\Resources\Audits\Pages;

use App\Filament\Resources\Audits\AuditResource;
use Tapp\FilamentAuditing\Filament\Resources\Audits\Pages\ListAudits as TappListAudits;

/** tapp's page, bound to our resource so its access check is the one that runs. */
class ListAudits extends TappListAudits
{
    protected static string $resource = AuditResource::class;
}
