<?php

namespace App\Models;

use App\Support\CurrentAccount;
use Tapp\FilamentAuditing\Models\Audit as FilamentAudit;

/**
 * Audit trail entry, stamped with the account it happened in so the history can be filtered per
 * account. Deliberately NOT scoped: entries written with no account (console, logins) keep a
 * null account_id. Extends tapp's model (itself owen-it's) so the history screens keep working.
 */
class Audit extends FilamentAudit
{
    protected static function booted(): void
    {
        static::creating(function (Audit $audit): void {
            $audit->account_id ??= CurrentAccount::get()?->getKey();
        });
    }
}
