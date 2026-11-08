<?php

namespace App\Support;

use App\Filament\Pages\AccountSubscription;
use App\Models\Account;
use App\Models\User;

/**
 * What the account-status banner at the top of the panel says: the trial days left, or that the
 * account is read-only. Exempt and paying accounts, and super admins, see nothing.
 */
final class AccountStatus
{
    /** @return array{kind: 'trial'|'read_only', message: string, subscribeUrl: ?string}|null */
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
                'message' => 'Esta conta está só de leitura: a experiência terminou e não há uma assinatura ativa. Podes ver e exportar tudo; para voltar a registar, subscreve.',
                'subscribeUrl' => self::subscribeUrl(),
            ];
        }

        if ($account->onGenericTrial() && ! $account->subscribed('default')) {
            return [
                'kind' => 'trial',
                'message' => self::trialMessage($account),
                'subscribeUrl' => self::subscribeUrl(),
            ];
        }

        return null;
    }

    /** Calendar days, so the count drops at midnight: "faltam 14 dias" on sign-up day, "último dia" on the last. */
    public static function trialMessage(Account $account): string
    {
        $days = (int) today()->diffInDays($account->trial_ends_at->copy()->startOfDay());

        return match ($days) {
            0 => 'Experiência: último dia',
            1 => 'Experiência: falta 1 dia',
            default => "Experiência: faltam {$days} dias",
        };
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
            return 'Isenta';
        }

        $subscription = $account->subscription('default');

        if ($subscription?->valid()) {
            $plan = AccountSubscription::planFor($subscription->stripe_price);

            return $plan === null ? 'Assinatura' : AccountSubscription::PLANS[$plan]['label'];
        }

        if ($account->onGenericTrial()) {
            return 'Experiência até '.$account->trial_ends_at->format('d/m/Y');
        }

        return 'Só leitura';
    }
}
