<?php

namespace App\Support;

use App\Models\Account;
use App\Models\InvoiceXpressIssueAttempt;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class InvoiceXpressIssueRepository
{
    public static function ownerCanIssue(): bool
    {
        $account = CurrentAccount::get();

        return $account !== null && $account->hasAccess() && AccountantAccess::canManage($account);
    }

    /** The snapshot is built only from the allowlisted invoice fields and verified fiscal terms. */
    public function reserve(string $intentKey, string $target, array $snapshot, int $userId): InvoiceXpressIssueAttempt
    {
        abort_unless(self::ownerCanIssue() && (int) auth()->id() === $userId, 403);

        return DB::transaction(function () use ($intentKey, $target, $snapshot, $userId): InvoiceXpressIssueAttempt {
            Account::query()->lockForUpdate()->findOrFail(CurrentAccount::id());
            abort_unless(self::ownerCanIssue() && (int) auth()->id() === $userId, 403);
            $attempt = InvoiceXpressIssueAttempt::where('intent_key', $intentKey)->first();

            if ($attempt !== null) {
                if ($attempt->provider_target !== $target || $attempt->snapshot !== $snapshot) {
                    throw ValidationException::withMessages(['confirmation' => __('An attempt with different details already exists for this operation. Review the recorded attempt.')]);
                }

                return $attempt;
            }

            $attempt = new InvoiceXpressIssueAttempt;
            $attempt->forceFill([
                'intent_key' => $intentKey,
                'provider_target' => $target,
                'snapshot' => $snapshot,
                'confirmed_by' => $userId,
                'proprietary_uid' => (string) Str::uuid(),
                'state' => InvoiceXpressIssueAttempt::Pending,
            ])->save();

            return $attempt;
        });
    }

    /** @param Closure(InvoiceXpressIssueAttempt): InvoiceXpressIssueAttempt $work */
    public function locked(int $id, Closure $work): InvoiceXpressIssueAttempt
    {
        abort_unless(self::ownerCanIssue(), 403);

        return DB::transaction(function () use ($id, $work): InvoiceXpressIssueAttempt {
            // Pin the configured InvoiceXpress account while the shared transport resolves it.
            Account::query()->lockForUpdate()->findOrFail(CurrentAccount::id());
            abort_unless(self::ownerCanIssue(), 403);

            return $work(InvoiceXpressIssueAttempt::query()->lockForUpdate()->findOrFail($id));
        });
    }
}
