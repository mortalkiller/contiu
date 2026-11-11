<?php

namespace App\Support;

use App\Enums\Language;
use App\Filament\Pages\AccountSubscription;
use App\Models\Account;
use App\Models\User;

/**
 * What the account-status banner at the top of the panel says: the trial days left, or that the
 * account is read-only. Exempt and paying accounts, and super admins, see nothing.
 */
final class AccountStatus
{
    /** @return array{kind: 'trial'|'read_only', message: string, detail?: string, urgent?: bool, subscribeUrl: ?string}|null */
    public static function banner(): ?array
    {
        $account = CurrentAccount::get();
        $user = auth()->user();

        if ($account === null || $account->billing_exempt || ($user instanceof User && $user->isSuperAdmin())) {
            return null;
        }

        if (! $account->hasAccess()) {
            return [
                'kind' => 'read_only',
                'message' => __('This account is read-only: the trial has ended and there is no active subscription. You can view and export everything. Subscribe to record new entries.'),
                'subscribeUrl' => self::subscribeUrl(),
            ];
        }

        if ($account->onGenericTrial() && ! $account->subscribed('default')) {
            return [
                'kind' => 'trial',
                'message' => self::trialMessage($account),
                'detail' => __('After that, the account becomes read-only: you can view and export everything, but not create or edit.'),
                // The last few days are in amber, as in the mock; before that the notice stays quiet.
                'urgent' => today()->diffInDays($account->trial_ends_at->copy()->startOfDay()) <= 3,
                'subscribeUrl' => self::subscribeUrl(),
            ];
        }

        return null;
    }

    /** Calendar days, so the count drops at midnight: "faltam 14 dias" on sign-up day, "último dia" on the last. */
    public static function trialMessage(Account $account): string
    {
        $days = (int) today()->diffInDays($account->trial_ends_at->copy()->startOfDay());

        return trans_choice('{0} Trial: last day|{1} Trial: 1 day left|[2,*] Trial: :count days left', $days);
    }

    /** Where "Subscrever" leads: the "Assinatura" page, when the user may open it. */
    public static function subscribeUrl(): ?string
    {
        return AccountSubscription::canAccess() ? AccountSubscription::getUrl() : null;
    }

    /**
     * The billing state in one word, for the super admins' list of accounts:
     * Isenta · Mensal · Anual · Experiência até d/m/Y · Só leitura.
     */
    public static function label(Account $account): string
    {
        if ($account->billing_exempt) {
            return __('Exempt');
        }

        $subscription = $account->subscription('default');

        if ($subscription?->valid()) {
            $plan = AccountSubscription::planFor($subscription->stripe_price);

            return match ($plan) {
                'monthly' => __('Monthly'),
                'yearly' => __('Yearly'),
                default => __('Subscription'),
            };
        }

        if ($account->onGenericTrial()) {
            return __('Trial until :date', ['date' => $account->trial_ends_at->format(Language::resolve(app()->getLocale())->dateFormat())]);
        }

        return __('Read-only');
    }
}
