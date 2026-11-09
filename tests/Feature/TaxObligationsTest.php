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
use App\Support\TaxEstimator;
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
        ->and($rows['ss:2026-11']->detail)->toContain('Pagar entre 10 e 20 de novembro')->toContain('contribuição de outubro')->toContain('rendimentos de jul–set')->toContain('contribuição mínima')
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
        // January pays December's contribution, set by the income of jul–set.
        ->and($rows['ss:2027-01']->detail)->toContain('contribuição de dezembro')->toContain('rendimentos de jul–set')
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

it('does not ask for the quarterly SS declaration from an account exempt from contributing', function () {
    // Art. 151.º-A, n.º 1: the quarterly declaration is for workers who must contribute.
    $exempt = [...OBLIGATIONS_COMPLETE_SETTINGS, 'ss_contribution_exempt' => true];

    expect(byKey(obligationsOn('2027-01-05', $exempt, since: '2026-01-01')->thisMonth()))->not->toHaveKey('ss-declaration:2026-Q4')
        ->and(byKey(obligationsOn('2026-11-09', $exempt)->upcoming()))->not->toHaveKey('ss-declaration:2026-Q4')
        ->and(byKey(obligationsOn('2026-11-09', [...OBLIGATIONS_COMPLETE_SETTINGS, 'ss_contribution_exempt' => false])->upcoming()))->toHaveKey('ss-declaration:2026-Q4');
});

it('reminds the SS annex with the IRS return', function () {
    expect(byKey(obligationsOn('2027-06-02')->thisMonth())['irs-modelo3:2026']->detail)->toContain('anexo SS');
});

it('lists overdue obligations from the date the account tracks them, before the account existed', function () {
    serviceBoughtAbroad('2026-04-25', 7921);
    serviceBoughtAbroad('2026-10-27', 8374);

    // Created in November 2026: without a tracking date, April's purchases are not listed.
    expect(byKey(obligationsOn('2026-11-09', OBLIGATIONS_COMPLETE_SETTINGS, since: '2026-11-08')->thisMonth()))
        ->not->toHaveKey('vat-reverse:2026-04')
        ->toHaveKey('vat-reverse:2026-10');

    $rows = byKey(obligationsOn('2026-11-09', [...OBLIGATIONS_COMPLETE_SETTINGS, 'obligations_tracked_from' => '2025-08-01'], since: '2026-11-08')->thisMonth());

    expect($rows['vat-reverse:2026-04'])->toMatchObject(['status' => 'overdue', 'amount' => 1822])
        ->and($rows['vat-reverse:2026-04']->dueOn->toDateString())->toBe('2026-05-31')
        ->and($rows)->toHaveKey('vat-reverse:2026-10');
});

it('still goes back at most 12 months from the tracking date', function () {
    serviceBoughtAbroad('2025-09-10', 5000);

    $rows = byKey(obligationsOn('2026-11-09', [...OBLIGATIONS_COMPLETE_SETTINGS, 'obligations_tracked_from' => '2025-01-01'], since: '2026-11-08')->thisMonth());

    // September 2025's VAT was due on 31 October 2025: more than 12 months before November 2026.
    expect($rows)->not->toHaveKey('vat-reverse:2025-09');
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

it('pays each month the contribution of the month before', function () {
    paymentFrom(Client::factory()->create(), '2026-08-10', 300000);
    paymentFrom(Client::factory()->create(), '2026-11-10', 100000);

    // November pays October's contribution, from the income of jul–set.
    $november = byKey(obligationsOn('2026-11-09')->thisMonth())['ss:2026-11'];

    expect($november->amount)->toBe(14980)
        ->and($november->detail)->toContain('contribuição de outubro')->toContain('rendimentos de jul–set');

    // January pays December's, still from jul–set; February pays January's, from out–dez.
    $january = byKey(obligationsOn('2027-01-05')->thisMonth())['ss:2027-01'];
    $february = byKey(obligationsOn('2027-02-03')->thisMonth())['ss:2027-02'];

    expect($january->amount)->toBe(14980)
        ->and($january->detail)->toContain('contribuição de dezembro')->toContain('rendimentos de jul–set')
        ->and($february->amount)->toBe(4993)
        ->and($february->detail)->toContain('contribuição de janeiro')->toContain('rendimentos de out–dez');
});

it('makes the first payment the month after enrolment', function () {
    $settings = [...OBLIGATIONS_COMPLETE_SETTINGS, 'ss_enrolled_from' => '2026-05-01'];

    // May's contribution is the first one: nothing is paid in May.
    $may = byKey(obligationsOn('2026-05-09', $settings)->thisMonth())['ss:2026-05'];
    $june = byKey(obligationsOn('2026-06-09', $settings)->thisMonth())['ss:2026-06'];

    expect($may)->toMatchObject(['status' => 'exempt', 'amount' => null, 'detail' => 'Isento (1.º enquadramento)'])
        ->and($june)->toMatchObject(['status' => 'due', 'amount' => 2000])
        ->and($june->detail)->toContain('contribuição de maio');
});

// Calendário: obligations of any range ------------------------------------------------------

/** @param list<TaxObligation> $obligations @return list<string> */
function keysOf(array $obligations): array
{
    return array_map(fn (TaxObligation $obligation) => $obligation->key, $obligations);
}

it('lists the obligations of any range, past and future, by deadline', function () {
    serviceBoughtAbroad('2026-10-15', 1000);
    $obligations = obligationsOn('2026-11-09', since: '2026-01-01');

    $keys = keysOf($obligations->between(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2027-01-31')));

    expect($keys)->toBe(['ss:2026-11', 'vat-reverse:2026-10', 'ss:2026-12', 'ss:2027-01', 'ss-declaration:2026-Q4']);
});

it('leaves out what is before the tracking start or more than 12 months back', function () {
    $obligations = obligationsOn('2026-11-09', since: '2026-06-15');

    $keys = keysOf($obligations->between(CarbonImmutable::parse('2025-01-01'), CarbonImmutable::parse('2026-07-31')));

    expect($keys)->toBe(['ss:2026-06', 'irs-modelo3:2025', 'ss:2026-07', 'ss-declaration:2026-Q2']);
});

it('stops 12 months back even when tracking started earlier', function () {
    $obligations = obligationsOn('2026-11-09', since: '2024-01-01');

    $keys = keysOf($obligations->between(CarbonImmutable::parse('2025-09-01'), CarbonImmutable::parse('2025-12-31')));

    expect($keys)->toBe(['ss:2025-11', 'ss:2025-12']);
});

it('leaves out exempt obligations, which have no deadline', function () {
    // First enrolment: the SS of November is exempt (no deadline).
    $obligations = obligationsOn('2026-11-09', ['activity_started_on' => '2026-09-01', 'activity_start_kind' => 'first']);

    expect(keysOf($obligations->between(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'))))
        ->not->toContain('ss:2026-11');
});

it('shows the SS amount only once its income quarter has closed', function () {
    $rows = byKey(obligationsOn('2026-11-09', since: '2026-01-01')
        ->between(CarbonImmutable::parse('2026-12-01'), CarbonImmutable::parse('2027-02-28')));

    expect($rows['ss:2026-12']->amount)->toBe(TaxEstimator::SS_MINIMUM_CONTRIBUTION)
        ->and($rows['ss:2027-01']->amount)->toBe(TaxEstimator::SS_MINIMUM_CONTRIBUTION)
        ->and($rows['ss:2027-02']->amount)->toBeNull()
        ->and($rows['ss:2027-02']->detail)->toContain('Pagar entre 10 e 20 de fevereiro')->toContain('valor por apurar')->not->toContain('contribuição mínima')
        ->and($rows['ss:2027-02']->dueOn->toDateString())->toBe('2027-02-20')
        ->and($rows['ss:2027-02']->status)->toBe('due');
});
