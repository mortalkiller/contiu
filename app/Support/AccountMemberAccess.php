<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Owners hold no Shield permissions: being a member of the current account is what lets them
 * use everything in it. Runs as a Gate::before callback, so it only ever grants (true) or
 * abstains (null) — never denies — and the system subjects stay with Shield and the
 * super-admin checks. Super admins keep their own path (Shield's gate) untouched.
 */
final class AccountMemberAccess
{
    /** Subjects that span every account: never granted by membership. */
    public const SYSTEM_SUBJECTS = ['User', 'Role', 'Audit', 'Account', 'SubscriptionPayment', 'Backups', 'ImportWorkbook'];

    /** @var array<string, bool> membership per "user:account", for the many checks of one page */
    private array $memberships = [];

    /** @param array<int, mixed> $arguments */
    public function before(mixed $user, string $ability, array $arguments): ?bool
    {
        if (! $user instanceof User || $user->isSuperAdmin()) {
            return null;
        }

        $subject = self::subject($ability, $arguments);

        if ($subject === null || in_array($subject, self::SYSTEM_SUBJECTS, true)) {
            return null;
        }

        $account = CurrentAccount::get();

        if ($account === null || ! $this->isMember($user, $account->getKey())) {
            return null;
        }

        return true;
    }

    /**
     * "ViewAny:Client" (Shield) → "Client"; "update" on a Client (policy) → "Client".
     *
     * @param  array<int, mixed>  $arguments
     */
    public static function subject(string $ability, array $arguments): ?string
    {
        if (str_contains($ability, ':')) {
            return substr($ability, strpos($ability, ':') + 1) ?: null;
        }

        $target = $arguments[0] ?? null;

        if ($target instanceof Model) {
            return class_basename($target);
        }

        if (is_string($target) && class_exists($target)) {
            return class_basename($target);
        }

        return null;
    }

    private function isMember(User $user, int $accountId): bool
    {
        return $this->memberships["{$user->getKey()}:{$accountId}"]
            ??= $user->accounts()->whereKey($accountId)->exists();
    }
}
