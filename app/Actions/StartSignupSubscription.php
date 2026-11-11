<?php

namespace App\Actions;

use App\Filament\Pages\AccountSubscription;
use App\Models\Account;
use RuntimeException;

/**
 * The subscription a new account starts at sign-up when the payment step is on
 * (contiu.signup_payment): the chosen plan, paid with the card collected by Stripe's Payment
 * Element, its first charge on the day the free trial ends. The billing address goes on the
 * Stripe customer, so every invoice carries it. Cashier's webhooks keep the subscription up to
 * date from then on.
 */
class StartSignupSubscription
{
    /** @param array{line1: string, postal_code: string, city: string} $address */
    public function handle(Account $account, string $plan, string $paymentMethod, array $address): void
    {
        $price = AccountSubscription::priceFor($plan)
            ?? throw new RuntimeException("Stripe price for the {$plan} plan is not configured (contiu.prices.{$plan}).");

        $account->createOrGetStripeCustomer(['address' => [...$address, 'country' => 'PT']]);

        $account->newSubscription('default', $price)
            ->trialUntil($account->trial_ends_at)
            ->create($paymentMethod);
    }
}
