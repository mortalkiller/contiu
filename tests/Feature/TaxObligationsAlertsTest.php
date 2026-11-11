<?php

use App\Mail\TaxObligationsDigest;
use App\Models\User;
use App\Support\FinanceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

pest()->group('integration', 'fiscal');

beforeEach(function () {
    Mail::fake();
    $this->alertsUser = User::factory()->create();
    $this->account->users()->attach($this->alertsUser, ['role' => 'owner']);
});

/**
 * Runs the command on $today for an account that exists since $since (default: the 1st of that
 * month), whose SS started long ago and that has the art. 53.º VAT exemption (no periodic VAT returns).
 */
function alertsPrepare(string $today, array $settings = ['activity_started_on' => '2024-01-01', 'activity_start_kind' => 'first', 'vat_exempt' => true], ?string $since = null): void
{
    test()->travelTo(Carbon::parse($today));
    test()->account->forceFill(['created_at' => $since ?? CarbonImmutable::parse($today)->startOfMonth(), 'billing_exempt' => true])->save();
    app(FinanceSettings::class)->set($settings);
}

function alertsRunOn(string $today, ?array $settings = null, ?string $since = null): void
{
    $settings === null ? alertsPrepare($today, since: $since) : alertsPrepare($today, $settings, $since);
    test()->artisan('finance:send-tax-obligations')->assertSuccessful();
}

function alertsTitles(): array
{
    return test()->alertsUser->fresh()->notifications->pluck('data.title')->all();
}

it('emails the month obligations on the 1st', function () {
    alertsRunOn('2026-11-01');

    Mail::assertQueued(TaxObligationsDigest::class, fn (TaxObligationsDigest $mail) => $mail->hasTo($this->alertsUser->email)
        && collect($mail->items)->contains(fn ($item) => str_contains($item['title'], 'Segurança Social')));
});

it('sends no email without obligations', function () {
    alertsRunOn('2026-11-01', []);

    Mail::assertNothingQueued();
});

it('notifies 3 days before a deadline, once', function () {
    alertsRunOn('2026-11-17');
    expect(alertsTitles())->toHaveCount(1)->and(alertsTitles()[0])->toContain('faltam 3 dias')
        // A user can be in several accounts: the body says which one.
        ->and($this->alertsUser->fresh()->notifications->first()->data['body'])->toStartWith($this->account->name.' · ');
    Mail::assertNothingQueued();

    alertsRunOn('2026-11-18');
    expect(alertsTitles())->toHaveCount(1);
});

it('skips read-only accounts', function () {
    alertsPrepare('2026-11-01');
    $this->account->forceFill(['trial_ends_at' => now()->subDay(), 'billing_exempt' => false])->save();
    $this->artisan('finance:send-tax-obligations')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('includes overdue obligations in the email', function () {
    alertsRunOn('2026-12-01', since: '2026-10-01');

    Mail::assertQueued(TaxObligationsDigest::class, fn (TaxObligationsDigest $mail) => collect($mail->items)->contains('overdue', true));
});

it('creates scheduled emails and notifications in each recipient’s language', function () {
    $englishUser = User::factory()->create(['language' => 'en-US']);
    $this->account->users()->attach($englishUser, ['role' => 'member']);

    alertsRunOn('2026-11-01');

    Mail::assertQueued(TaxObligationsDigest::class, fn (TaxObligationsDigest $mail) => $mail->hasTo($englishUser->email)
        && collect($mail->items)->contains(fn ($item) => str_contains($item['title'], 'Social Security')));

    alertsRunOn('2026-11-17');

    expect($englishUser->notifications()->first()->data['title'])->toContain('3 days left')
        ->and(alertsTitles()[0])->toContain('faltam 3 dias');
});
