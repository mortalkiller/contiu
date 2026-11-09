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

    $quarters = estimator(2026)->quarters();

    expect($quarters)->toHaveCount(4)
        ->and($quarters[0])->toMatchArray(['label' => 'Jan – Mar', 'income' => 100000, 'base' => 23333, 'monthly' => 4993, 'chargedMonths' => 3, 'total' => 14979, 'status' => 'charged'])
        ->and($quarters[1])->toMatchArray(['label' => 'Abr – Jun', 'income' => 300000, 'base' => 70000, 'monthly' => 14980, 'total' => 44940])
        ->and($quarters[2])->toMatchArray(['income' => 0, 'monthly' => TaxEstimator::SS_MINIMUM_CONTRIBUTION, 'total' => 6000]);
});

it('charges the 20 € minimum in a quarter without income', function () {
    $estimate = estimator(2026, ['activity_started_on' => '2024-01-01', 'activity_start_kind' => 'first']);

    expect(TaxEstimator::SS_MINIMUM_CONTRIBUTION)->toBe(2000)
        ->and($estimate->ssContributionForMonth(CarbonImmutable::parse('2026-05-01')))->toBe(2000);
});

it('charges the 20 € minimum when the computed contribution is lower', function () {
    payOn('2026-08-10', 25550);

    $estimate = estimator(2026, ['activity_started_on' => '2024-01-01', 'activity_start_kind' => 'first']);

    // base 5962 × 21,4 % = 1276, below the minimum.
    expect($estimate->quarters()[2])->toMatchArray(['base' => 5962, 'monthly' => 2000])
        ->and($estimate->ssContributionForMonth(CarbonImmutable::parse('2026-11-01')))->toBe(2000);
});

it('keeps the computed contribution above the minimum', function () {
    payOn('2026-02-10', 100000);

    $estimate = estimator(2026, ['activity_started_on' => '2024-01-01', 'activity_start_kind' => 'first']);

    expect($estimate->ssContributionForMonth(CarbonImmutable::parse('2026-05-01')))->toBe(4993);
});

it('starts the obligation on the SS enrolment date', function () {
    // The computed start (January 2025) gives way to what the Segurança Social says.
    $estimate = estimator(2026, ['activity_started_on' => '2024-01-01', 'activity_start_kind' => 'first', 'ss_enrolled_from' => '2026-05-01']);

    expect($estimate->ssObligationStartsOn()->toDateString())->toBe('2026-05-01')
        ->and($estimate->ssObligationIsEstimated())->toBeFalse()
        ->and($estimate->ssMonthStatus(CarbonImmutable::parse('2026-04-01')))->toBe('first_enrolment')
        ->and($estimate->ssMonthStatus(CarbonImmutable::parse('2026-05-01')))->toBe('charged')
        ->and($estimate->ssContributionForMonth(CarbonImmutable::parse('2026-04-01')))->toBe(0)
        ->and($estimate->ssContributionForMonth(CarbonImmutable::parse('2026-05-01')))->toBe(2000)
        ->and($estimate->quarters()[0])->toMatchArray(['chargedMonths' => 2, 'total' => 4000, 'status' => 'first_enrolment']);
});

it('computes the first-enrolment start without an enrolment date', function () {
    $first = estimator(2026, ['activity_started_on' => '2025-08-07', 'activity_start_kind' => 'first']);

    expect($first->ssObligationStartsOn()->toDateString())->toBe('2026-08-01')
        ->and($first->ssObligationIsEstimated())->toBeTrue()
        ->and($first->ssMonthStatus(CarbonImmutable::parse('2026-07-01')))->toBe('first_enrolment')
        ->and($first->ssMonthStatus(CarbonImmutable::parse('2026-08-01')))->toBe('charged');

    // Incomplete settings (no kind) count as a first start.
    $incomplete = estimator(2026, ['activity_started_on' => '2025-08-07', 'activity_start_kind' => null]);

    expect($incomplete->ssObligationStartsOn()->toDateString())->toBe('2026-08-01');

    $restart = estimator(2025, ['activity_started_on' => '2025-08-07', 'activity_start_kind' => 'restart']);

    expect($restart->ssObligationStartsOn()->toDateString())->toBe('2025-08-01')
        ->and($restart->ssObligationIsEstimated())->toBeTrue()
        ->and($restart->ssMonthStatus(CarbonImmutable::parse('2025-07-01')))->toBe('first_enrolment')
        ->and($restart->ssMonthStatus(CarbonImmutable::parse('2025-08-01')))->toBe('charged');
});

it('obliges every month without a start date', function () {
    $estimate = estimator(2026);

    expect($estimate->ssObligationStartsOn())->toBeNull()
        ->and($estimate->ssMonthStatus(CarbonImmutable::parse('2026-01-01')))->toBe('charged');
});

it('charges nothing with a recognised contribution exemption', function () {
    payOn('2026-02-10', 100000);

    $estimate = estimator(2026, ['activity_started_on' => '2024-01-01', 'activity_start_kind' => 'first', 'ss_contribution_exempt' => true]);

    foreach (range(1, 12) as $month) {
        expect($estimate->ssMonthStatus(CarbonImmutable::create(2026, $month, 1)))->toBe('exempt')
            ->and($estimate->ssContributionForMonth(CarbonImmutable::create(2026, $month, 1)))->toBe(0);
    }

    expect($estimate->ssYearTotal())->toBe(0)
        ->and($estimate->quarters()[0])->toMatchArray(['chargedMonths' => 0, 'status' => 'exempt']);
});

it('counts only payments not marked tax exempt, still with the minimum', function () {
    payOn('2026-05-10', 300000)->update(['tax_exempt' => true]);

    $estimate = estimator(2026, ['activity_started_on' => '2024-01-01', 'activity_start_kind' => 'first']);

    expect($estimate->ssContributionForMonth(CarbonImmutable::parse('2026-07-01')))->toBe(2000);
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
