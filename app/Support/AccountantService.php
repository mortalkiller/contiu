<?php

namespace App\Support;

use App\Mail\AccountantServiceRequested;
use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Laravel\Cashier\Subscription;
use Throwable;

/**
 * The certified-accountant service: a partner's certified accountant, dedicated to the account and
 * with accountant access to it, who handles the VAT returns, the Social Security quarterly
 * declaration and the IRS return. Paid monthly, on top of the Contiu plan, after the free trial.
 *
 * Two prices: the simplified regime, and organised accounting, which is more work and where a
 * certified accountant is mandatory. The account keeps which one it asked for (accountant_service)
 * and when; with Stripe prices configured, it is also a Cashier subscription of its own (TYPE),
 * apart from the plan's, since a yearly plan and a monthly service cannot share one.
 */
final class AccountantService
{
    /** The Cashier subscription type of the service. */
    public const TYPE = 'accountant';

    /**
     * Amounts as charged by the Stripe prices in config('contiu.accountant_prices'): cents a month,
     * no VAT. The accountant's own reference values (13/11/2026): 75 € in the simplified regime and
     * 200 € with organised accounting, the same for ENI and TI.
     */
    public const PLANS = [
        'simplified' => ['amount' => 7500],
        'organised' => ['amount' => 20000],
    ];

    /** The service for an accounting regime: organised accounting has its own price. */
    public static function planFor(?string $accountingRegime): string
    {
        return $accountingRegime === 'organised' ? 'organised' : 'simplified';
    }

    /** What the customer pays a month, VAT included (contiu.subscription_vat_rate), in cents. */
    public static function priceWithVat(string $plan): int
    {
        if (! isset(self::PLANS[$plan])) {
            throw new InvalidArgumentException("Unknown accountant service [{$plan}].");
        }

        return Money::round(self::PLANS[$plan]['amount'] * (1 + (float) config('contiu.subscription_vat_rate')));
    }

    /** The Stripe price of the service; null while it is not configured (STRIPE_PRICE_ACCOUNTANT_* unset). */
    public static function stripePrice(string $plan): ?string
    {
        if (! isset(self::PLANS[$plan])) {
            throw new InvalidArgumentException("Unknown accountant service [{$plan}].");
        }

        return config("contiu.accountant_prices.{$plan}") ?: null;
    }

    /** The service's Stripe subscription, while it is valid (on trial, active or until it ends). */
    public static function subscription(Account $account): ?Subscription
    {
        $subscription = $account->subscription(self::TYPE);

        return $subscription?->valid() ? $subscription : null;
    }

    /**
     * Records that the account asked for the service and, the first time, tells the super admins
     * (AccountantServiceRequested), who introduce the partner's accountant.
     */
    public static function request(Account $account, string $plan): void
    {
        $isNew = $account->accountant_service === null;

        $account->forceFill([
            'accountant_service' => $plan,
            'accountant_service_requested_at' => $account->accountant_service_requested_at ?? now(),
        ])->save();

        if ($isNew) {
            self::notifySuperAdmins($account);
        }
    }

    /** The account no longer wants the service. */
    public static function withdraw(Account $account): void
    {
        $account->forceFill(['accountant_service' => null, 'accountant_service_requested_at' => null])->save();
    }

    /** A mail that cannot be queued is only reported: the request is saved and shows on the account. */
    private static function notifySuperAdmins(Account $account): void
    {
        // whereHas, not Spatie's role() scope: that one throws while the role does not exist.
        User::query()
            ->whereHas('roles', fn ($roles) => $roles->where('name', config('filament-shield.super_admin.name', 'super_admin')))
            ->get()
            ->each(function (User $admin) use ($account): void {
                try {
                    Mail::to($admin)->queue(new AccountantServiceRequested($account));
                } catch (Throwable $exception) {
                    report($exception);
                }
            });
    }
}
