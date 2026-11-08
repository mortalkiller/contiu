<?php

use App\Models\Expense;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Support\FinanceSettings;
use App\Support\TaxEstimator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-11-06')));

function payOn(string $date, int $amount, float $vat = 0): Payment
{
    $project = Project::factory()->create(['net_amount' => $amount, 'vat_rate' => $vat, 'quoted_on' => $date]);
    $receivable = Receivable::factory()->for($project, 'billable')->create(['amount' => $amount, 'due_on' => $date]);

    return Payment::factory()->for($receivable)->create(['paid_on' => $date, 'amount' => $amount]);
}

function estimator(int $year, array $settings = []): TaxEstimator
{
    app(FinanceSettings::class)->set($settings);

    return new TaxEstimator(app(FinanceSettings::class), $year);
}

it('splits the year income into quarters with the Segurança Social base and monthly contribution', function () {
    payOn('2026-02-10', 100000);
    payOn('2026-05-20', 300000);

    $quarters = estimator(2026, ['ss_first_year_exempt' => false])->quarters();

    expect($quarters)->toHaveCount(4)
        ->and($quarters[0])->toMatchArray(['label' => 'Jan – Mar', 'income' => 100000, 'base' => 23333, 'monthly' => 4993, 'chargedMonths' => 3, 'total' => 14979])
        ->and($quarters[1])->toMatchArray(['label' => 'Abr – Jun', 'income' => 300000, 'base' => 70000, 'monthly' => 14980, 'total' => 44940])
        ->and($quarters[2]['income'])->toBe(0);
});

it('exempts the contribution months that fall within the first 12 months of activity', function () {
    payOn('2026-11-15', 300000);

    $estimate = estimator(2026, ['activity_started_on' => '2026-02-01', 'ss_first_year_exempt' => true]);
    $q4 = $estimate->quarters()[3];

    // Q4 income is paid in Jan, Feb and Mar 2027; January 2027 is still within the first year.
    expect($q4['chargedMonths'])->toBe(2)
        ->and($q4['total'])->toBe(29960)
        ->and($estimate->ssContributionForMonth(CarbonImmutable::parse('2027-01-01')))->toBe(0)
        ->and($estimate->ssContributionForMonth(CarbonImmutable::parse('2027-02-01')))->toBe(14980);
});

it('charges every month when the first-year exemption is turned off', function () {
    payOn('2026-11-15', 300000);

    $q4 = estimator(2026, ['activity_started_on' => '2026-02-01', 'ss_first_year_exempt' => false])->quarters()[3];

    expect($q4['chargedMonths'])->toBe(3);
});

it('bases a month contribution on the income of the previous quarter', function () {
    payOn('2026-02-10', 100000);

    $estimate = estimator(2026, ['ss_first_year_exempt' => false]);

    expect($estimate->ssContributionForMonth(CarbonImmutable::parse('2026-05-01')))->toBe(4993)
        ->and($estimate->ssContributionForMonth(CarbonImmutable::parse('2026-02-01')))->toBe(0);
});

it('reports IRS as exempt or as the simplified-regime taxable income', function () {
    payOn('2026-03-01', 400000);

    expect(estimator(2026, ['irs_exempt' => true])->irsExempt())->toBeTrue()
        ->and(estimator(2026)->irsTaxableIncome())->toBe(0)
        ->and(estimator(2026, ['irs_exempt' => false])->irsTaxableIncome())->toBe(300000);
});

it('tracks the VAT exemption threshold', function (int $income, string $status, float $percent) {
    payOn('2026-03-01', $income);

    $estimate = estimator(2026, ['vat_exemption_threshold' => 1500000]);

    expect($estimate->vatThresholdStatus())->toBe($status)
        ->and($estimate->vatThresholdPercent())->toBe($percent);
})->with([
    [500000, 'ok', 33.3],
    [1200000, 'warning', 80.0],
    [1500000, 'danger', 100.0],
]);

it('computes VAT collected and paid when not exempt', function () {
    payOn('2026-03-01', 12300, vat: 0.23);
    Expense::factory()->create(['spent_on' => '2026-03-05', 'net_amount' => 10000, 'vat_rate' => 0.23]);

    $estimate = estimator(2026, ['vat_exempt' => false]);

    expect($estimate->vatCollected())->toBe(2300)
        ->and($estimate->vatPaid())->toBe(2300)
        ->and($estimate->vatBalance())->toBe(0);
});
