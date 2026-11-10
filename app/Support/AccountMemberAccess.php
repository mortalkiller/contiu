<?php

namespace App\Support;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Owners hold no Shield permissions: being a member of the current account is what lets them
 * use it. Accountants get access through the review pages and may view individual expense proofs.
 * System subjects stay with Shield and the super-admin checks.
 */
final class AccountMemberAccess
{
    /** Subjects that span every account: never granted by membership. */
    public const SYSTEM_SUBJECTS = ['User', 'Role', 'Audit', 'Account', 'SubscriptionPayment', 'Backups', 'ImportWorkbook'];

    /** @var array<string, string|null> role per "user:account" for the many checks of one page */
    private array $roles = [];

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

        if ($account === null) {
            return null;
        }

        $key = "{$user->getKey()}:{$account->getKey()}";
        if (! array_key_exists($key, $this->roles)) {
            $this->roles[$key] = AccountantAccess::role($user, $account);
        }

        $role = $this->roles[$key];
        if ($role === AccountantAccess::ROLE) {
            $record = $arguments[0] ?? null;

            return $ability === 'view'
                && $record instanceof Expense
                && (int) $record->account_id === $account->getKey()
                && $user->hasVerifiedEmail();
        }

        return $role === null ? null : true;
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
}
