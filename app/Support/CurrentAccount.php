<?php

namespace App\Support;

use App\Exceptions\NoCurrentAccount;
use App\Models\Account;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * Holds the account the current request/job works for. Registered as `scoped`
 * so it resets between requests and queued jobs; the static helpers resolve it.
 */
final class CurrentAccount
{
    private ?Account $account = null;

    public static function set(?Account $account): void
    {
        app(self::class)->account = $account;
    }

    /** The explicitly set account, else the Filament tenant (panel requests). */
    public static function get(): ?Account
    {
        $explicit = app(self::class)->account;

        if ($explicit !== null) {
            return $explicit;
        }

        $tenant = Filament::getTenant();

        return $tenant instanceof Account ? $tenant : null;
    }

    public static function id(): int
    {
        return self::get()?->getKey() ?? throw new NoCurrentAccount;
    }

    /**
     * The current account has no access (trial over, nothing paying): it can be seen and exported,
     * not changed. Super admins are never held back; with no account there is nothing to protect.
     */
    public static function readOnly(): bool
    {
        $account = self::get();

        if ($account === null || $account->hasAccess()) {
            return false;
        }

        $user = auth()->user();

        return ! ($user instanceof User && $user->isSuperAdmin());
    }

    /** Runs the callback as the given account and always restores the previous one. */
    public static function run(Account $account, callable $callback): mixed
    {
        $holder = app(self::class);
        $previous = $holder->account;
        $holder->account = $account;

        try {
            return $callback();
        } finally {
            $holder->account = $previous;
        }
    }
}
