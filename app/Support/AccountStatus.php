<?php

namespace App\Support;

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

    /**
     * Where "Subscrever" leads. TODO(Task 9): return AccountSubscription::getUrl() once the
     * "Assinatura" page exists; until then the banner shows no link.
     */
    public static function subscribeUrl(): ?string
    {
        return null;
    }
}
