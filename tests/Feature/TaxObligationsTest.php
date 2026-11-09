<?php

use App\Enums\Seat;
use App\Models\Account;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\Supplier;
use App\Models\TaxObligationCompletion;
use App\Models\User;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;
use App\Support\TaxObligation;
use App\Support\TaxObligations;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/** Settings of an account whose SS obligation started long ago. */
const OBLIGATIONS_COMPLETE_SETTINGS = ['activity_started_on' => '2024-01-01', 'activity_start_kind' => 'first'];

/**
 * The obligations seen on $today by an account that exists since $since (default: the 1st of
 * $today's month, so no earlier month is overdue unless the test says so).
 */
function obligationsOn(string $today, array $settings = OBLIGATIONS_COMPLETE_SETTINGS, ?string $since = null): TaxObligations
{
    $today = CarbonImmutable::parse($today);
    test()->travelTo(Carbon::parse($today));
    test()->account->forceFill(['created_at' => $since ?? $today->startOfMonth(), 'billing_exempt' => true])->save();
    app(FinanceSettings::class)->set($settings);

    return new TaxObligations(new FinanceSettings, $today);
}

/** @param list<TaxObligation> $obligations @return array<string, TaxObligation> */
function byKey(array $obligations): array
{
    return collect($obligations)->keyBy('key')->all();
}

function serviceBoughtAbroad(string $on, int $net): Expense
{
    return Expense::factory()->for(Supplier::factory()->create(['seat' => Seat::EuropeanUnion]), 'supplier')
        ->create(['spent_on' => $on, 'is_goods' => false, 'vat_rate' => 0, 'net_amount' => $net]);
}

function paymentFrom(Client $client, string $on, int $amount): Payment
{
    $project = Project::factory()->for($client)->create(['net_amount' => $amount, 'quoted_on' => $on]);
    $receivable = Receivable::factory()->for($project, 'billable')->create(['amount' => $amount, 'due_on' => $on]);

    return Payment::factory()->for($receivable)->create(['paid_on' => $on, 'amount' => $amount]);
}

it('lists what is due this month with amount and deadline', function () {
    serviceBoughtAbroad('2026-10-15', 1000);

    $obligations = obligationsOn('2026-11-09');
    $rows = byKey($obligations->thisMonth());
    $today = CarbonImmutable::parse('2026-11-09');

    expect(array_keys($rows))->toBe(['ss:2026-11', 'vat-reverse:2026-10'])
        ->and($rows['ss:2026-11'])->toMatchObject(['kind' => 'ss', 'title' => 'Segurança Social', 'amount' => 2000, 'status' => 'due'])
        ->and($rows['ss:2026-11']->dueOn->toDateString())->toBe('2026-11-20')
        ->and($rows['ss:2026-11']->opensOn->toDateString())->toBe('2026-11-10')
        ->and($rows['ss:2026-11']->detail)->toContain('Pagar entre 10 e 20 de novembro')->toContain('rendimentos de jul–set')->toContain('contribuição mínima')
        ->and($rows['ss:2026-11']->daysLeft($today))->toBe(11)
        ->and($rows['vat-reverse:2026-10'])->toMatchObject(['kind' => 'vat-reverse', 'amount' => 230, 'status' => 'due'])
        ->and($rows['vat-reverse:2026-10']->dueOn->toDateString())->toBe('2026-11-30')
        ->and($rows['vat-reverse:2026-10']->url)->not->toBeNull()
        ->and(array_keys(byKey($obligations->pending())))->toBe(['ss:2026-11', 'vat-reverse:2026-10'])
        ->and(array_keys(byKey($obligations->upcoming())))->toBe(['ss-declaration:2026-Q4']);
});

it('shows the January set across the year boundary', function () {
    serviceBoughtAbroad('2026-11-20', 5000);

    $rows = byKey(obligationsOn('2027-01-05', since: '2026-12-01')->thisMonth());

    expect($rows)->toHaveKeys(['ss:2027-01', 'ss-declaration:2026-Q4', 'vat-reverse:2026-11'])
        ->and($rows['ss:2027-01']->detail)->toContain('rendimentos de out–dez')
        ->and($rows['ss-declaration:2026-Q4'])->toMatchObject(['kind' => 'ss-declaration', 'amount' => null, 'status' => 'due'])
        ->and($rows['ss-declaration:2026-Q4']->dueOn->toDateString())->toBe('2027-01-31')
        ->and($rows['vat-reverse:2026-11'])->toMatchObject(['status' => 'overdue', 'amount' => 1150])
        ->and($rows['vat-reverse:2026-11']->dueOn->toDateString())->toBe('2026-12-31')
        ->and($rows['vat-reverse:2026-11']->daysLeft(CarbonImmutable::parse('2027-01-05')))->toBe(-5)
        // December's SS (due 20 Dec) was never marked either.
        ->and($rows['ss:2026-12']->status)->toBe('overdue')
        // Overdue ones come first: ordered by deadline.
        ->and(array_key_first($rows))->toBe('ss:2026-12');
});

it('drops completed obligations and reopens them', function () {
    $obligations = obligationsOn('2026-11-09');
    $user = User::factory()->create();

    $obligations->complete('ss:2026-11', $user);

    $completion = TaxObligationCompletion::query()->where('key', 'ss:2026-11')->sole();

    expect(byKey($obligations->thisMonth()))->not->toHaveKey('ss:2026-11')
        ->and($completion->user_id)->toBe($user->id)
        ->and($completion->account_id)->toBe($this->account->id)
        ->and(byKey($obligations->done())['ss:2026-11']->status)->toBe('done');

    $obligations->reopen('ss:2026-11');

    expect(byKey($obligations->thisMonth()))->toHaveKey('ss:2026-11')
        ->and($obligations->done())->toBe([])
        ->and(TaxObligationCompletion::query()->count())->toBe(0);
});

it('does not list an earlier month that was marked done', function () {
    $obligations = obligationsOn('2026-11-09', since: '2026-10-01');

    expect(byKey($obligations->thisMonth())['ss:2026-10']->status)->toBe('overdue');

    $obligations->complete('ss:2026-10', null);

    expect(byKey($obligations->thisMonth()))->not->toHaveKey('ss:2026-10');
});

it('shows SS as exempt without a deadline', function () {
    $rows = byKey(obligationsOn('2026-11-09', [...OBLIGATIONS_COMPLETE_SETTINGS, 'ss_contribution_exempt' => true], since: '2026-01-01')->thisMonth());

    expect($rows['ss:2026-11'])->toMatchObject(['status' => 'exempt', 'amount' => null, 'dueOn' => null, 'opensOn' => null, 'detail' => 'Isento (art. 157.º)'])
        ->and($rows['ss:2026-11']->daysLeft(CarbonImmutable::parse('2026-11-09')))->toBeNull()
        // Exempt months are never overdue.
        ->and(collect($rows)->where('kind', 'ss'))->toHaveCount(1)
        ->and(obligationsOn('2026-11-09', [...OBLIGATIONS_COMPLETE_SETTINGS, 'ss_contribution_exempt' => true])->pending())->toBe([]);
});

it('shows SS as exempt during the first enrolment', function () {
    $row = byKey(obligationsOn('2026-11-09', ['activity_started_on' => '2026-03-02', 'activity_start_kind' => 'first'])->thisMonth())['ss:2026-11'];

    expect($row)->toMatchObject(['status' => 'exempt', 'dueOn' => null, 'detail' => 'Isento (1.º enquadramento)']);
});

it('asks to complete the settings when they are incomplete', function () {
    serviceBoughtAbroad('2026-10-15', 1000);

    $obligations = obligationsOn('2026-11-09', ['activity_started_on' => '2024-01-01', 'activity_start_kind' => null]);
    $rows = byKey($obligations->thisMonth());

    expect(array_keys($rows))->toBe(['vat-reverse:2026-10', 'incomplete-settings'])
        ->and($rows['incomplete-settings'])->toMatchObject(['status' => 'info', 'title' => 'Completa as definições fiscais'])
        ->and($rows['incomplete-settings']->url)->toContain('definicoes-fiscais')
        ->and($obligations->upcoming())->toBe([]);
});

it('lists the recapitulativa only for EU business clients', function () {
    paymentFrom(Client::factory()->create(['seat' => Seat::EuropeanUnion, 'is_business' => false]), '2026-10-05', 10000);
    paymentFrom(Client::factory()->create(['seat' => Seat::OutsideEu, 'is_business' => true]), '2026-10-06', 10000);
    paymentFrom(Client::factory()->create(['seat' => Seat::Portugal, 'is_business' => true]), '2026-10-07', 10000);

    expect(byKey(obligationsOn('2026-11-09')->thisMonth()))->not->toHaveKey('recapitulativa:2026-10');

    paymentFrom(Client::factory()->create(['seat' => Seat::EuropeanUnion, 'is_business' => true]), '2026-10-08', 10000);

    $row = byKey(obligationsOn('2026-11-09')->thisMonth())['recapitulativa:2026-10'];

    expect($row)->toMatchObject(['kind' => 'recapitulativa', 'amount' => null, 'status' => 'due'])
        ->and($row->dueOn->toDateString())->toBe('2026-11-20');
});

it('keeps completions per account', function () {
    $other = Account::factory()->create(['billing_exempt' => true]);
    $obligations = obligationsOn('2026-11-09');

    $obligations->complete('ss:2026-11', null);

    $otherRows = CurrentAccount::run($other, function () {
        app(FinanceSettings::class)->set(OBLIGATIONS_COMPLETE_SETTINGS);

        return byKey((new TaxObligations(new FinanceSettings, CarbonImmutable::parse('2026-11-09')))->thisMonth());
    });

    expect($otherRows['ss:2026-11']->status)->toBe('due')
        ->and(byKey($obligations->thisMonth()))->not->toHaveKey('ss:2026-11');
});

it('shows the IRS return in June and as upcoming from April', function () {
    expect(byKey(obligationsOn('2027-03-10')->upcoming()))->not->toHaveKey('irs-modelo3:2026')
        ->and(byKey(obligationsOn('2027-04-10')->upcoming()))->toHaveKey('irs-modelo3:2026')
        ->and(byKey(obligationsOn('2027-04-10')->thisMonth()))->not->toHaveKey('irs-modelo3:2026');

    $row = byKey(obligationsOn('2027-06-02')->thisMonth())['irs-modelo3:2026'];

    expect($row->dueOn->toDateString())->toBe('2027-06-30')
        ->and(byKey(obligationsOn('2027-06-02')->upcoming()))->not->toHaveKey('irs-modelo3:2026');
});

it('shows the VAT threshold and foreign VAT warnings as info', function () {
    paymentFrom(Client::factory()->create(), '2026-03-10', 1200000);
    $withVat = Expense::factory()->for(Supplier::factory()->create(['seat' => Seat::OutsideEu]), 'supplier')
        ->create(['spent_on' => '2026-10-12', 'is_goods' => false, 'vat_rate' => 0.23, 'net_amount' => 1000]);

    $rows = byKey(obligationsOn('2026-11-09', [...OBLIGATIONS_COMPLETE_SETTINGS, 'vat_exempt' => true])->thisMonth());

    expect($rows['vat-threshold:2026'])->toMatchObject(['kind' => 'vat-threshold', 'status' => 'info', 'amount' => 1200000])
        ->and($rows['foreign-vat-warning:'.$withVat->id])->toMatchObject(['kind' => 'foreign-vat-warning', 'status' => 'info'])
        ->and(array_key_last($rows))->toBe('foreign-vat-warning:'.$withVat->id)
        ->and(byKey(obligationsOn('2026-11-09', [...OBLIGATIONS_COMPLETE_SETTINGS, 'vat_exempt' => false])->thisMonth()))->not->toHaveKey('vat-threshold:2026');
});
