<?php

namespace App\Listeners;

use App\Filament\Pages\AccountSubscription;
use App\Mail\TrialEndingSoon;
use App\Models\Account;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookReceived;
use Throwable;

/**
 * Stripe sends customer.subscription.trial_will_end three days before a trial ends: the account's
 * owner gets TrialEndingSoon, once per subscription and trial end (Stripe retries a webhook until
 * it gets a 2xx). The amount is the plan's price with VAT, as Stripe will charge it.
 *
 * The Stripe webhook endpoint must send customer.subscription.trial_will_end: it is not among the
 * events `php artisan cashier:webhook` subscribes to by default.
 */
class SendTrialEndingNotice
{
    public function handle(WebhookReceived $event): void
    {
        if (($event->payload['type'] ?? null) !== 'customer.subscription.trial_will_end') {
            return;
        }

        $subscription = $event->payload['data']['object'];
        $account = Cashier::findBillable($subscription['customer'] ?? null);
        $trialEnd = $subscription['trial_end'] ?? null;
        $plan = AccountSubscription::planFor(Arr::get($subscription, 'items.data.0.price.id'));

        if (! $account instanceof Account || $trialEnd === null || $plan === null || ($owner = $account->owner()) === null) {
            return;
        }

        if (! Cache::add('trial-ending-notice:'.$subscription['id'].':'.$trialEnd, true, now()->addDays(30))) {
            return;
        }

        try {
            Mail::to($owner)->locale($owner->preferredLocale())->queue(new TrialEndingSoon(
                $account,
                CarbonImmutable::createFromTimestamp($trialEnd, 'Europe/Lisbon'),
                $plan,
                AccountSubscription::priceWithVat($plan),
            ));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
