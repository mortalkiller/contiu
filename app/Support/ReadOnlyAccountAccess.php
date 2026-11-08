<?php

namespace App\Support;

/**
 * Hides the write buttons of a read-only account: a Gate::before that denies the write abilities
 * (policy names and Shield's "Create:Client" form) and abstains on everything else. It only
 * hides; BelongsToAccount is what actually refuses the write. It cannot reach users holding
 * Spatie permissions (that gate answers first) nor super admins, who are never read-only.
 */
final class ReadOnlyAccountAccess
{
    public const WRITE_ABILITIES = [
        'create', 'update', 'delete', 'deleteAny', 'restore', 'restoreAny',
        'forceDelete', 'forceDeleteAny', 'replicate', 'reorder',
    ];

    public static function before(string $ability): ?bool
    {
        return self::isWrite($ability) && CurrentAccount::readOnly() ? false : null;
    }

    /** "update" (policy) or "Update:Client" (Shield). */
    public static function isWrite(string $ability): bool
    {
        $action = str_contains($ability, ':') ? strstr($ability, ':', true) : $ability;

        return in_array(lcfirst($action), self::WRITE_ABILITIES, true);
    }
}
