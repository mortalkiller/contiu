<?php

namespace App\Actions;

use App\Filament\Pages\AccountSubscription;
use App\Models\Account;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription;
use RuntimeException;
use Throwable;

/**
 * The subscription a new account starts at sign-up when the payment step is on
 * (contiu.signup_payment).
 *
 * 1. setupIntentSecret(): before the account exists, a SetupIntent for future, off-session charges.
 *    Stripe's Payment Element confirms it in the browser, asking the bank's authentication
 *    (3D Secure) when it wants it, so the first charge at the end of the trial goes through. The
 *    payment methods are the ones turned on in the Stripe dashboard (card, SEPA Direct Debit…); the
 *    Payment Element shows the SEPA mandate itself. Redirect methods are not allowed: the sign-up
 *    would lose its answers.
 * 2. handle(): with the confirmed payment method, the customer (billing address for the invoices)
 *    and the subscription to the chosen plan, VAT added (Account::taxRates()), its first charge on
 *    the day the trial ends. Cashier's webhooks keep it up to date from then on.
 * 3. undo(): when the account could not be saved after all, the customer and its subscription are
 *    removed from Stripe, so no one is charged for an account that does not exist.
 */
class StartSignupSubscription
{
    public function setupIntentSecret(): string
    {
        return Cashier::stripe()->setupIntents->create([
            'usage' => 'off_session',
            'automatic_payment_methods' => ['enabled' => true, 'allow_redirects' => 'never'],
        ])->client_secret;
    }

    /** @param array{line1: string, postal_code: string, city: string} $address */
    public function handle(Account $account, string $plan, string $paymentMethod, array $address): Subscription
    {
        $price = AccountSubscription::priceFor($plan)
            ?? throw new RuntimeException("Stripe price for the {$plan} plan is not configured (contiu.prices.{$plan}).");

        $account->createOrGetStripeCustomer(['address' => [...$address, 'country' => 'PT']]);

        return $account->newSubscription('default', $price)
            ->trialUntil($account->trial_ends_at)
            ->create($paymentMethod);
    }

    public function undo(?string $customerId): void
    {
        if ($customerId === null) {
            return;
        }

        try {
            // Deleting the customer cancels its subscriptions at once, with nothing charged.
            Cashier::stripe()->customers->delete($customerId);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
