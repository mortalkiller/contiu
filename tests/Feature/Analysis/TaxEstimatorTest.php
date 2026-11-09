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

it('uses the configured IRS coefficient', function () {
    payOn('2026-03-01', 400000);

    $estimate = estimator(2026, ['irs_coefficient' => 0.35, 'activity_started_on' => '2020-01-01', 'activity_start_kind' => 'first']);

    expect($estimate->irsCoefficientReduction())->toBe(0.0)
        ->and($estimate->effectiveIrsCoefficient())->toBe(0.35)
        ->and($estimate->irsTaxableIncome())->toBe(140000);
});

it('reduces the coefficient in the first two years', function () {
    $settings = ['activity_started_on' => '2025-08-07', 'activity_start_kind' => 'first'];

    expect(estimator(2025, $settings)->irsCoefficientReduction())->toBe(0.5)
        ->and(estimator(2025, $settings)->effectiveIrsCoefficient())->toBe(0.375)
        ->and(estimator(2026, $settings)->irsCoefficientReduction())->toBe(0.25)
        ->and(estimator(2026, $settings)->effectiveIrsCoefficient())->toBe(0.5625)
        ->and(estimator(2027, $settings)->irsCoefficientReduction())->toBe(0.0)
        ->and(estimator(2027, $settings)->effectiveIrsCoefficient())->toBe(0.75);
});

it('drops the reduction in a year with salary or pension', function () {
    $settings = ['activity_started_on' => '2025-08-07', 'activity_start_kind' => 'first', 'dependent_income_years' => [2026]];

    expect(estimator(2026, $settings)->effectiveIrsCoefficient())->toBe(0.75)
        ->and(estimator(2026, $settings)->irsCoefficientReductionBlocker())->toBe('dependent_income')
        ->and(estimator(2025, $settings)->effectiveIrsCoefficient())->toBe(0.375)
        ->and(estimator(2025, $settings)->irsCoefficientReductionBlocker())->toBeNull();
});

it('names no blocker once the early years are over', function () {
    $settings = ['activity_started_on' => '2020-08-07', 'activity_start_kind' => 'restart', 'dependent_income_years' => [2026]];

    expect(estimator(2026, $settings)->irsCoefficientReductionBlocker())->toBeNull();
});

it('drops the reduction after a recent cessation', function () {
    $restart = ['activity_started_on' => '2025-08-07', 'activity_start_kind' => 'restart'];

    expect(estimator(2025, [...$restart, 'previous_activity_ended_on' => '2022-01-01'])->irsCoefficientReduction())->toBe(0.0)
        ->and(estimator(2025, [...$restart, 'previous_activity_ended_on' => '2019-01-01'])->irsCoefficientReduction())->toBe(0.5)
        // Without the cessation date the reduction is not assumed.
        ->and(estimator(2025, [...$restart, 'previous_activity_ended_on' => null])->irsCoefficientReduction())->toBe(0.0)
        ->and(estimator(2025, [...$restart, 'previous_activity_ended_on' => '2022-01-01'])->irsCoefficientReductionBlocker())->toBe('recent_restart');
});

it('gives no reduction without an activity start', function () {
    expect(estimator(2026)->irsCoefficientReduction())->toBe(0.0)
        ->and(estimator(2026)->effectiveIrsCoefficient())->toBe(0.75);
});

it('exempts part of the gross income with IRS Jovem before the coefficient', function () {
    payOn('2026-03-01', 2000000);

    $estimate = estimator(2026, [
        'activity_started_on' => '2025-08-07', 'activity_start_kind' => 'first',
        'irs_jovem' => true, 'irs_jovem_year' => ['year' => 2026, 'number' => 2],
    ]);

    expect($estimate->irsJovemPercent())->toBe(0.75)
        ->and($estimate->irsJovemExempt())->toBe(1500000)
        ->and($estimate->effectiveIrsCoefficient())->toBe(0.5625)
        ->and($estimate->irsTaxableIncome())->toBe(281250);
});

it('takes the IRS Jovem percentage from the year number', function (int $number, float $percent) {
    $estimate = estimator(2026, ['irs_jovem' => true, 'irs_jovem_year' => ['year' => 2026, 'number' => $number]]);

    expect($estimate->irsJovemPercent())->toBe($percent);
})->with([
    [0, 0.0], [1, 1.0], [2, 0.75], [4, 0.75], [5, 0.5], [7, 0.5], [8, 0.25], [10, 0.25], [11, 0.0],
]);

it('gives no IRS Jovem when it is off or the year number is unknown', function () {
    expect(estimator(2026, ['irs_jovem' => false, 'irs_jovem_year' => ['year' => 2026, 'number' => 1]])->irsJovemPercent())->toBe(0.0)
        ->and(estimator(2026, ['irs_jovem' => true, 'irs_jovem_year' => null])->irsJovemPercent())->toBe(0.0);
});

it('caps IRS Jovem at 55 times the IAS', function () {
    payOn('2026-03-01', 4000000);

    $estimate = estimator(2026, ['irs_jovem' => true, 'irs_jovem_year' => ['year' => 2026, 'number' => 1]]);

    expect($estimate->irsJovemPercent())->toBe(1.0)
        ->and($estimate->irsJovemExempt())->toBe(2954215);
});

it('gives no IRS Jovem after turning 35', function () {
    $jovem = ['irs_jovem' => true, 'irs_jovem_year' => ['year' => 2026, 'number' => 2]];

    expect(estimator(2026, [...$jovem, 'birth_date' => '1990-06-01'])->irsJovemPercent())->toBe(0.0)
        // 35 on 31/12 still counts.
        ->and(estimator(2026, [...$jovem, 'birth_date' => '1991-12-31'])->irsJovemPercent())->toBe(0.75);
});

it('marks the IAS provisional for a year not in the table', function () {
    expect(estimator(2026)->ias())->toBe(53713)
        ->and(estimator(2026)->iasIsProvisional())->toBeFalse()
        ->and(estimator(2027)->ias())->toBe(53713)
        ->and(estimator(2027)->iasIsProvisional())->toBeTrue();
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
    [1875000, 'critical', 125.0],
]);

it('computes VAT collected and paid when not exempt', function () {
    payOn('2026-03-01', 12300, vat: 0.23);
    Expense::factory()->create(['spent_on' => '2026-03-05', 'net_amount' => 10000, 'vat_rate' => 0.23]);

    $estimate = estimator(2026, ['vat_exempt' => false]);

    expect($estimate->vatCollected())->toBe(2300)
        ->and($estimate->vatPaid())->toBe(2300)
        ->and($estimate->vatBalance())->toBe(0);
});
