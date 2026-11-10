<?php

namespace App\Support;

use App\Models\Account;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class AccountantAccess
{
    public const ROLE = 'accountant';

    public static function role(User $user, Account $account): ?string
    {
        return $user->accounts()->whereKey($account->getKey())->value('account_user.role');
    }

    public static function isReviewOnly(?Account $account = null): bool
    {
        $account ??= CurrentAccount::get();
        $user = auth()->user();

        return $account !== null
            && $user instanceof User
            && ! $user->isSuperAdmin()
            && self::role($user, $account) === self::ROLE;
    }

    public static function canReview(Account $account): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->hasVerifiedEmail()
            && $user->canAccessTenant($account);
    }

    public static function canResolve(Account $account): bool
    {
        return self::canReview($account) && ! self::isReviewOnly($account);
    }

    public static function canManage(Account $account): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->hasVerifiedEmail()
            && self::role($user, $account) === 'owner';
    }

    public function grant(string $email): void
    {
        $accountId = CurrentAccount::id();

        Account::query()->getConnection()->transaction(function () use ($accountId, $email): void {
            $account = Account::query()->lockForUpdate()->findOrFail($accountId);
            abort_unless(self::canManage($account) && $account->hasAccess(), 403);

            $user = User::query()->where('email', trim($email))->whereNotNull('email_verified_at')->first();

            if ($user === null || $user->isSuperAdmin()) {
                throw ValidationException::withMessages(['email' => 'Indica o endereço de um utilizador com email confirmado.']);
            }

            // A grant never overwrites a different account role, even if another process adds it.
            $account->users()->newPivotStatement()->insertOrIgnore([
                'account_id' => $accountId,
                'user_id' => $user->getKey(),
                'role' => self::ROLE,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (self::role($user, $account) !== self::ROLE) {
                throw ValidationException::withMessages(['email' => 'Este utilizador já tem acesso à conta com outro papel.']);
            }
        });
    }

    public function revoke(int $userId): void
    {
        $accountId = CurrentAccount::id();

        Account::query()->getConnection()->transaction(function () use ($accountId, $userId): void {
            $account = Account::query()->lockForUpdate()->findOrFail($accountId);
            abort_unless(self::canManage($account), 403);

            $account->users()->wherePivot('role', self::ROLE)->detach($userId);
        });
    }
}
