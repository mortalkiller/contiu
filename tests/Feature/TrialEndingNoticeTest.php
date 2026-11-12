<?php

use App\Mail\TrialEndingSoon;
use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/*
 * Three days before the trial ends Stripe sends customer.subscription.trial_will_end, and the
 * account's owner gets the email promised at sign-up: when, how much with VAT, how to cancel.
 */

pest()->group('integration');

beforeEach(function () {
    Mail::fake();
    config(['contiu.prices.monthly' => 'price_monthly', 'contiu.prices.yearly' => 'price_yearly', 'cashier.webhook.secret' => null]);
    $this->account->forceFill(['stripe_id' => 'cus_trial'])->save();
    $this->owner = tap(User::factory()->create(), fn (User $user) => $this->account->users()->attach($user, ['role' => 'owner']));
});

/** A trial_will_end event as Stripe sends it (only the fields the app reads). */
function trialWillEndEvent(Account $account, string $price = 'price_yearly', string $subscription = 'sub_1'): array
{
    return [
        'id' => 'evt_'.Str::random(10),
        'type' => 'customer.subscription.trial_will_end',
        'data' => ['object' => [
            'id' => $subscription,
            'object' => 'subscription',
            'customer' => $account->stripe_id,
            'trial_end' => Carbon::parse('2026-11-25 10:00', 'Europe/Lisbon')->timestamp,
            'items' => ['data' => [['price' => ['id' => $price]]]],
        ]],
    ];
}

it('emails the owner the first charge with VAT, once', function () {
    $this->postJson(route('cashier.webhook'), trialWillEndEvent($this->account))->assertOk();
    $this->postJson(route('cashier.webhook'), trialWillEndEvent($this->account))->assertOk();

    Mail::assertQueued(TrialEndingSoon::class, 1);
    Mail::assertQueued(TrialEndingSoon::class, fn (TrialEndingSoon $mail): bool => $mail->hasTo($this->owner->email)
        && $mail->amountInCents === 6642
        && $mail->planKey === 'yearly'
        && $mail->chargeOn->toDateString() === '2026-11-25');
});

it('says when, how much and how to cancel', function () {
    $this->postJson(route('cashier.webhook'), trialWillEndEvent($this->account, price: 'price_monthly'))->assertOk();

    Mail::assertQueued(TrialEndingSoon::class, function (TrialEndingSoon $mail): bool {
        $mail->locale('pt_PT');
        $mail->assertSeeInHtml('25/11/2026')
            ->assertSeeInHtml('6,15')
            ->assertSeeInHtml('IVA incluído')
            ->assertSeeInHtml('cancela antes de');

        return true;
    });
});

it('ignores other events and subscriptions it cannot place', function () {
    $this->postJson(route('cashier.webhook'), [...trialWillEndEvent($this->account), 'type' => 'invoice.upcoming'])->assertOk();
    $this->postJson(route('cashier.webhook'), trialWillEndEvent($this->account, price: 'price_other'))->assertOk();

    Mail::assertNothingQueued();
});
