<?php

use App\Enums\Seat;
use App\Models\Account;
use App\Models\Client;
use App\Models\Expense;
use App\Models\FiscalDocument;
use App\Models\FiscalProfile;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\Supplier;
use App\Models\TaxObligationCompletion;
use App\Models\User;
use App\Support\CurrentAccount;
use App\Support\EuServiceEvidence;
use App\Support\FinanceSettings;
use App\Support\SocialSecurity;
use App\Support\TaxEstimator;
use App\Support\TaxObligation;
use App\Support\TaxObligations;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

pest()->group('integration', 'fiscal');

/** Settings of an account whose SS obligation started long ago. */
const OBLIGATIONS_COMPLETE_SETTINGS = ['activity_started_on' => '2024-01-01', 'activity_start_kind' => 'first'];

/**
 * The same account with the art. 53.º VAT exemption: it self-assesses the VAT of foreign services
 * on its own (vat-reverse), instead of in the periodic VAT return.
 */
const OBLIGATIONS_VAT_EXEMPT_SETTINGS = [...OBLIGATIONS_COMPLETE_SETTINGS, 'vat_exempt' => true];

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

    $obligations = obligationsOn('2026-11-09', OBLIGATIONS_VAT_EXEMPT_SETTINGS);
    $rows = byKey($obligations->thisMonth());
    $today = CarbonImmutable::parse('2026-11-09');

    expect(array_keys($rows))->toBe(['ss:2026-11', 'vat-reverse:2026-10', 'vat-threshold:2026'])
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

    $rows = byKey(obligationsOn('2027-01-05', OBLIGATIONS_VAT_EXEMPT_SETTINGS, since: '2026-12-01')->thisMonth());

    expect($rows)->toHaveKeys(['ss:2027-01', 'ss-declaration:2026-Q4', 'ss-annual:2026', 'vat-reverse:2026-11'])
        // January pays December's contribution, set by the income of jul–set.
        ->and($rows['ss:2027-01']->detail)->toContain('contribuição de dezembro')->toContain('rendimentos de jul–set')
        ->and($rows['ss-declaration:2026-Q4'])->toMatchObject(['kind' => 'ss-declaration', 'amount' => null, 'status' => 'due'])
        // 31 January 2027 is a Sunday: the deadline moves to Monday (SS052).
        ->and($rows['ss-declaration:2026-Q4']->dueOn->toDateString())->toBe('2027-02-01')
        ->and($rows['ss-annual:2026']->dueOn->toDateString())->toBe('2027-02-01')
        ->and($rows['vat-reverse:2026-11'])->toMatchObject(['status' => 'overdue', 'amount' => 1150])
        ->and($rows['vat-reverse:2026-11']->dueOn->toDateString())->toBe('2026-12-31')
        ->and($rows['vat-reverse:2026-11']->daysLeft(CarbonImmutable::parse('2027-01-05')))->toBe(-5)
        // December's SS (due 20 Dec) was never marked either.
        ->and($rows['ss:2026-12']->status)->toBe('overdue')
        // Overdue ones come first: ordered by deadline.
        ->and(array_key_first($rows))->toBe('ss:2026-12');
});

it('opens only closed declarations and uses the confirmed SS amount on the calendar deadline', function () {
    $obligations = obligationsOn('2026-11-09', since: '2026-09-01');
    actingAsAdmin();
    app(SocialSecurity::class)->confirmContribution('2026-Q3', '2026-10', 12345);

    $rows = byKey($obligations->thisMonth());

    expect($rows['ss:2026-11']->amount)->toBe(12345)
        ->and($rows['ss:2026-11']->url)->toContain('seguranca-social', 'quarter=2026-Q3')
        ->and($rows['ss-declaration:2026-Q3']->url)->toContain('seguranca-social', 'quarter=2026-Q3')
        ->and(byKey($obligations->upcoming())['ss-declaration:2026-Q4']->url)->toBeNull();
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
        ->and(obligationsOn('2026-11-09', [...OBLIGATIONS_VAT_EXEMPT_SETTINGS, 'ss_contribution_exempt' => true])->pending())->toBe([]);
});

it('shows SS as exempt during the first enrolment', function () {
    $row = byKey(obligationsOn('2026-11-09', ['activity_started_on' => '2026-03-02', 'activity_start_kind' => 'first'])->thisMonth())['ss:2026-11'];

    expect($row)->toMatchObject(['status' => 'exempt', 'dueOn' => null, 'detail' => 'Isento (1.º enquadramento)']);
});

it('asks to complete the settings when they are incomplete', function () {
    serviceBoughtAbroad('2026-10-15', 1000);

    $obligations = obligationsOn('2026-11-09', ['activity_started_on' => '2024-01-01', 'activity_start_kind' => null, 'vat_exempt' => true]);
    $rows = byKey($obligations->thisMonth());

    expect(array_keys($rows))->toBe(['vat-reverse:2026-10', 'incomplete-settings', 'vat-threshold:2026'])
        ->and($rows['incomplete-settings'])->toMatchObject(['status' => 'info', 'title' => 'Completa as definições fiscais'])
        ->and($rows['incomplete-settings']->url)->toContain('definicoes-fiscais')
        ->and($obligations->upcoming())->toBe([]);
});

function recordRecapService(string $operationOn, array $attributes = []): FiscalDocument
{
    $client = Client::factory()->create([
        'fiscal_country_code' => 'DE',
        'fiscal_party_type' => 'business',
        'vat_number' => 'DE123456789',
    ]);
    $client->forceFill(['vat_validation_status' => 'valid', 'vat_validated_at' => $attributes['document_date'] ?? now()])->save();

    $document = FiscalDocument::factory()->create($attributes + [
        'operation_date' => $operationOn,
        'party_tax_id' => 'DE123456789',
        'vat_amount' => 0,
        'total_amount' => 10000,
    ]);
    EuServiceEvidence::record($document, EuServiceEvidence::terms($client, $operationOn));

    return $document;
}

it('keeps legacy EU receipts and documents with missing evidence visible for review', function () {
    paymentFrom(Client::factory()->create(['seat' => Seat::EuropeanUnion, 'is_business' => false]), '2026-10-05', 10000);
    paymentFrom(Client::factory()->create(['seat' => Seat::OutsideEu, 'is_business' => true]), '2026-10-06', 10000);
    paymentFrom(Client::factory()->create(['seat' => Seat::Portugal, 'is_business' => true]), '2026-10-07', 10000);

    $obligations = obligationsOn('2026-11-09');
    expect(byKey($obligations->thisMonth()))->not->toHaveKey('recapitulativa-review');

    paymentFrom(Client::factory()->create(['seat' => Seat::EuropeanUnion, 'is_business' => true]), '2026-10-08', 10000);
    FiscalDocument::factory()->create(['party_tax_id' => 'FRAB123456789', 'vat_amount' => 0, 'total_amount' => 10000]);

    $rows = byKey($obligations->thisMonth());

    expect($rows)->not->toHaveKey('recapitulativa:2026-10')
        ->and($rows['recapitulativa-review'])->toMatchObject(['amount' => null, 'dueOn' => null, 'status' => 'info'])
        ->and($rows['recapitulativa-review']->detail)->toContain('1 documento', '1 recebimento');
});

it('flags an unpaid historic document with an unprefixed VAT number when its linked charge identifies an EU business', function () {
    $obligations = obligationsOn('2026-11-09');
    $client = Client::factory()->create(['fiscal_country_code' => 'DE', 'fiscal_party_type' => 'business']);
    $project = Project::factory()->for($client)->create();
    $charge = Receivable::factory()->for($project, 'billable')->create();
    FiscalDocument::factory()->create(['party_tax_id' => '123456789', 'vat_amount' => 0, 'total_amount' => 10000])
        ->links()->create(['receivable_id' => $charge->id]);

    $rows = byKey($obligations->thisMonth());
    expect($rows['recapitulativa-review']->detail)->toBe('1 documento sem evidência da operação');
});

it('uses the monthly operation date even if the invoice and payment are in later months', function () {
    $obligations = obligationsOn('2026-11-09');
    FiscalProfile::factory()->create(['effective_from' => '2026-01-01', 'vat_regime' => 'Regime normal mensal']);
    $document = recordRecapService('2026-10-31', ['document_date' => '2026-11-05']);
    $paid = paymentFrom(Client::factory()->create(['seat' => Seat::EuropeanUnion, 'is_business' => true]), '2026-11-08', 10000);
    $document->links()->create(['payment_id' => $paid->id]);
    $obligations->complete('recapitulativa:2026-10', null);

    $rows = byKey($obligations->thisMonth());
    $row = $rows['recapitulativa:operacoes:2026-10'];

    expect($rows)->not->toHaveKey('recapitulativa-review')
        ->and($row)->toMatchObject(['kind' => 'recapitulativa', 'amount' => null, 'status' => 'due'])
        ->and($row->dueOn->toDateString())->toBe('2026-11-20')
        ->and($row->period())->toBe('outubro')
        ->and(byKey($obligations->between(CarbonImmutable::parse('2026-12-01'), CarbonImmutable::parse('2026-12-31'))))
        ->not->toHaveKey('recapitulativa:operacoes:2026-11');

    $obligations->complete($row->key, null);
    expect(byKey($obligations->done()))->toHaveKey($row->key);
});

it('keeps quarterly services quarterly even above the intra-EU goods threshold', function () {
    $obligations = obligationsOn('2026-10-05', since: '2026-07-01');
    FiscalProfile::factory()->create(['effective_from' => '2026-01-01', 'vat_regime' => 'Regime normal trimestral']);
    recordRecapService('2026-07-03', ['net_amount' => 5_000_001, 'total_amount' => 5_000_001]);
    recordRecapService('2026-08-03');

    $row = byKey($obligations->thisMonth())['recapitulativa:operacoes:2026-Q3'];

    expect($row->dueOn->toDateString())->toBe('2026-10-20')
        ->and($row->period())->toBe('3.º trimestre')
        ->and(byKey($obligations->between(CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-09-30'))))
        ->not->toHaveKeys(['recapitulativa:operacoes:2026-07', 'recapitulativa:operacoes:2026-08']);
});

it('applies August fiscal holidays and weekend rollovers to monthly recap deadlines', function () {
    $obligations = obligationsOn('2026-09-01', since: '2026-05-01');
    FiscalProfile::factory()->create(['effective_from' => '2026-01-01', 'vat_regime' => 'Regime normal mensal']);
    recordRecapService('2026-05-15');
    recordRecapService('2026-07-15');
    recordRecapService('2026-08-15');
    $rows = byKey($obligations->thisMonth());

    expect($rows['recapitulativa:operacoes:2026-05']->dueOn->toDateString())->toBe('2026-06-22')
        ->and($rows['recapitulativa:operacoes:2026-07']->dueOn->toDateString())->toBe('2026-08-31')
        ->and($rows['recapitulativa:operacoes:2026-08']->dueOn->toDateString())->toBe('2026-09-21');
});

it('surfaces verified operations with unconfirmed periodicity and drops voided documents from the recap', function () {
    $obligations = obligationsOn('2026-11-09');
    FiscalProfile::factory()->create(['effective_from' => '2026-01-01']);
    $document = recordRecapService('2026-10-10');

    $rows = byKey($obligations->thisMonth());
    expect($rows)->not->toHaveKey('recapitulativa:operacoes:2026-10')
        ->and($rows['recapitulativa-review']->detail)->toContain('1 operação');

    FiscalProfile::factory()->create(['effective_from' => '2026-10-01', 'vat_regime' => 'Regime normal mensal']);
    expect(byKey($obligations->thisMonth()))->toHaveKey('recapitulativa:operacoes:2026-10');

    $document->update(['status' => 'voided']);
    expect(byKey($obligations->thisMonth()))->not->toHaveKeys(['recapitulativa:operacoes:2026-10', 'recapitulativa-review'])
        ->and($document->fiscalOperationEvidence()->exists())->toBeTrue();
});

it('excludes an edited invoice from the recap and preserves the original evidence for review', function (array $changes) {
    $obligations = obligationsOn('2026-11-09');
    FiscalProfile::factory()->create(['effective_from' => '2026-01-01', 'vat_regime' => 'Regime normal mensal']);
    $document = recordRecapService('2026-10-10');
    $document->update($changes);

    $rows = byKey($obligations->thisMonth());
    expect($rows)->not->toHaveKey('recapitulativa:operacoes:2026-10')
        ->and($rows['recapitulativa-review']->detail)->toContain('1 documento alterado')
        ->and($document->fiscalOperationEvidence()->sole()->net_amount)->toBe(10000);
})->with([
    'total' => [['total_amount' => 10001]],
    'net' => [['net_amount' => 10001]],
    'VAT' => [['vat_amount' => 2300]],
    'party VAT number' => [['party_tax_id' => 'DE987654321']],
    'document number' => [['number' => 'FT 2026/REV']],
    'issued date' => [['document_date' => '2026-11-08']],
    'operation date' => [['operation_date' => '2026-10-09']],
    'operation date removed' => [['operation_date' => null]],
]);

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
    expect(byKey(obligationsOn('2026-11-09', OBLIGATIONS_VAT_EXEMPT_SETTINGS, since: '2026-11-08')->thisMonth()))
        ->not->toHaveKey('vat-reverse:2026-04')
        ->toHaveKey('vat-reverse:2026-10');

    $rows = byKey(obligationsOn('2026-11-09', [...OBLIGATIONS_VAT_EXEMPT_SETTINGS, 'obligations_tracked_from' => '2025-08-01'], since: '2026-11-08')->thisMonth());

    expect($rows['vat-reverse:2026-04'])->toMatchObject(['status' => 'overdue', 'amount' => 1822])
        // 31 May 2026 is a Sunday: the deadline moves to Monday.
        ->and($rows['vat-reverse:2026-04']->dueOn->toDateString())->toBe('2026-06-01')
        ->and($rows)->toHaveKey('vat-reverse:2026-10');
});

it('still goes back at most 12 months from the tracking date', function () {
    serviceBoughtAbroad('2025-09-10', 5000);

    $rows = byKey(obligationsOn('2026-11-09', [...OBLIGATIONS_VAT_EXEMPT_SETTINGS, 'obligations_tracked_from' => '2025-01-01'], since: '2026-11-08')->thisMonth());

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
        ->and($rows['vat-threshold:2026']->detail)->toContain('aproximação por recebimentos')
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
    $obligations = obligationsOn('2026-11-09', OBLIGATIONS_VAT_EXEMPT_SETTINGS, since: '2026-01-01');

    $keys = keysOf($obligations->between(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2027-02-01')));

    expect($keys)->toBe(['ss:2026-11', 'vat-reverse:2026-10', 'ss:2026-12', 'ss:2027-01', 'ss-declaration:2026-Q4', 'ss-annual:2026']);
});

it('leaves out what is before the tracking start or more than 12 months back', function () {
    $obligations = obligationsOn('2026-11-09', since: '2026-06-15');

    $keys = keysOf($obligations->between(CarbonImmutable::parse('2025-01-01'), CarbonImmutable::parse('2026-07-31')));

    expect($keys)->toBe(['ss:2026-06', 'irs-modelo3:2025', 'ss:2026-07', 'ss-declaration:2026-Q2']);
});

it('stops 12 months back even when tracking started earlier', function () {
    $obligations = obligationsOn('2026-11-09', since: '2024-01-01');

    $keys = keysOf($obligations->between(CarbonImmutable::parse('2025-09-01'), CarbonImmutable::parse('2025-12-31')));

    expect($keys)->toBe(['ss:2025-11', 'vat-return:2025-Q3', 'ss:2025-12']);
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
        // 20 February 2027 is a Saturday: the payment can be made on Monday.
        ->and($rows['ss:2027-02']->dueOn->toDateString())->toBe('2027-02-22')
        ->and($rows['ss:2027-02']->status)->toBe('due');
});
