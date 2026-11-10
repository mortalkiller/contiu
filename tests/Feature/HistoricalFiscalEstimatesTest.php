<?php

use App\Actions\ConfirmFiscalProfile;
use App\Filament\Pages\FinanceSettingsPage;
use App\Models\Account;
use App\Models\Expense;
use App\Models\FiscalProfile;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\User;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;
use App\Support\Money;
use App\Support\TaxEstimator;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

function historicalProfileInput(array $overrides = []): array
{
    return [
        'legal_name' => 'Titular',
        'country_code' => 'PT',
        'vat_number' => 'PT123456789',
        'vat_regime' => 'Regime normal',
        'effective_from' => '2025-04-15',
        'confirmed' => true,
        ...$overrides,
    ];
}

it('uses only explicit profile fields and discloses fallback before, between and after versions', function () {
    $actor = actingAsAdmin();
    $settings = app(FinanceSettings::class);
    $settings->set(['vat_exempt' => false, 'vat_exemption_threshold' => 1500000]);
    $confirm = app(ConfirmFiscalProfile::class);
    $confirm->handle($actor, historicalProfileInput(['vat_exempt' => 'no']));
    $corrected = $confirm->handle($actor, historicalProfileInput([
        'vat_exempt' => 'yes',
        'vat_exemption_threshold' => 1234567,
    ]));
    $confirm->handle($actor, historicalProfileInput([
        'effective_from' => '2025-09-01',
        'vat_regime' => 'Isenção artigo 53.º',
        'recognised_exemptions' => 'Isento de contribuições',
        'has_dependent_employment' => 'yes',
    ]));
    $confirm->handle($actor, historicalProfileInput(['effective_from' => '2026-01-01', 'vat_exempt' => 'yes']));
    $estimate = new TaxEstimator($settings, 2025);

    $periods = $estimate->vatPeriods();

    expect($periods)->toHaveCount(3)
        ->and($periods[0])->toMatchArray(['from' => '2025-01-01', 'to' => '2025-04-14', 'exempt' => false, 'threshold' => 1500000])
        ->and($periods[1])->toMatchArray(['from' => '2025-04-15', 'to' => '2025-08-31', 'exempt' => true, 'threshold' => 1234567])
        ->and($periods[2])->toMatchArray(['from' => '2025-09-01', 'to' => '2025-12-31', 'exempt' => false, 'threshold' => 1500000])
        ->and($estimate->fiscalBasisOn(CarbonImmutable::parse('2025-04-14'))['confirmed_fields'])->toBe([])
        ->and($estimate->fiscalBasisOn(CarbonImmutable::parse('2025-04-15')))->toBe([
            'profile_id' => $corrected->id,
            'effective_from' => '2025-04-15',
            'confirmed_fields' => ['vat_exempt', 'vat_exemption_threshold'],
        ])
        ->and($estimate->fiscalBasisOn(CarbonImmutable::parse('2025-09-01'))['confirmed_fields'])->toBe([])
        ->and($estimate->vatExempt())->toBeFalse()
        ->and($estimate->ssMonthStatus(CarbonImmutable::parse('2025-09-01')))->toBe('charged');

    $other = Account::factory()->create();
    CurrentAccount::run($other, function () use ($estimate) {
        expect($estimate->fiscalBasisOn(CarbonImmutable::parse('2025-04-15')))->toBe([
            'profile_id' => null,
            'effective_from' => null,
            'confirmed_fields' => [],
        ]);
    });
});

it('applies the confirmed contribution exemption month by month and preserves a confirmed false', function () {
    $actor = actingAsAdmin();
    $settings = app(FinanceSettings::class);
    $settings->set(['ss_contribution_exempt' => true]);
    $confirm = app(ConfirmFiscalProfile::class);
    $confirm->handle($actor, historicalProfileInput(['ss_contribution_exempt' => 'no']));
    $confirm->handle($actor, historicalProfileInput(['effective_from' => '2025-06-01', 'ss_contribution_exempt' => 'yes']));
    $estimate = new TaxEstimator($settings, 2025);

    expect($estimate->ssContributionForMonth(CarbonImmutable::parse('2025-04-30')))->toBe(0)
        ->and($estimate->ssContributionForMonth(CarbonImmutable::parse('2025-05-01')))->toBe(2000)
        ->and($estimate->ssPaymentInMonth(CarbonImmutable::parse('2025-06-01')))->toBe(2000)
        ->and($estimate->ssContributionForMonth(CarbonImmutable::parse('2025-06-01')))->toBe(0)
        ->and($estimate->quarters()[0])->toMatchArray(['chargedMonths' => 1, 'total' => 2000, 'status' => 'exempt'])
        ->and($estimate->ssYearTotal())->toBe(2000);
});

it('calculates VAT amounts within each period and uses the annual receipt proxy for exemption progress', function () {
    FiscalProfile::factory()->create([
        'effective_from' => '2025-01-01',
        'vat_exempt' => true,
        'vat_exemption_threshold' => 100000,
    ]);
    FiscalProfile::factory()->create(['effective_from' => '2025-07-01', 'vat_exempt' => false]);
    Payment::factory()->create(['paid_on' => '2025-06-30', 'amount' => 40000, 'tax_exempt' => false]);
    $project = Project::factory()->create(['net_amount' => 10000, 'vat_rate' => 0.23]);
    $receivable = Receivable::factory()->for($project, 'billable')->create(['amount' => 12300]);
    Payment::factory()->for($receivable)->create(['paid_on' => '2025-07-01', 'amount' => 12300, 'tax_exempt' => false]);
    Expense::factory()->create(['spent_on' => '2025-06-30', 'net_amount' => 10000, 'vat_rate' => 0.23, 'is_paid' => true]);
    Expense::factory()->create(['spent_on' => '2025-07-01', 'net_amount' => 5000, 'vat_rate' => 0.23, 'is_paid' => true]);
    $estimate = new TaxEstimator(app(FinanceSettings::class), 2025);

    $periods = $estimate->vatPeriods();

    expect($periods[0])->toMatchArray(['exempt' => true, 'thresholdPercent' => 40.0, 'collected' => null, 'paid' => null, 'balance' => null])
        ->and($periods[1])->toMatchArray(['exempt' => false, 'thresholdPercent' => null, 'collected' => 2300, 'paid' => 1150, 'balance' => 1150])
        ->and($estimate->vatPaid())->toBe(3450);
});

it('uses today for the ongoing annual summary and still resolves a scheduled VAT change on its date', function () {
    $this->travelTo(CarbonImmutable::parse('2025-06-30'));
    $settings = app(FinanceSettings::class);
    $settings->set(['vat_exempt' => false]);
    FiscalProfile::factory()->create(['effective_from' => '2025-07-01', 'vat_exempt' => true]);
    $estimate = new TaxEstimator($settings, 2025);

    expect($estimate->vatExempt())->toBeFalse()
        ->and($estimate->vatExempt(CarbonImmutable::parse('2025-07-01')))->toBeTrue();
});

it('uses a confirmed activity start for the calculation period and keeps the annual employment input separate', function () {
    $settings = app(FinanceSettings::class);
    $settings->set(['activity_started_on' => '2020-01-01', 'activity_start_kind' => 'first', 'dependent_income_years' => [2026]]);
    FiscalProfile::factory()->create([
        'effective_from' => '2025-08-07',
        'activity_started_on' => '2025-08-07',
        'has_dependent_employment' => false,
    ]);

    expect((new TaxEstimator($settings, 2024))->irsCoefficientReduction())->toBe(0.0)
        ->and((new TaxEstimator($settings, 2025))->irsCoefficientReduction())->toBe(0.5)
        ->and((new TaxEstimator($settings, 2026))->irsCoefficientReductionBlocker())->toBe('dependent_income')
        ->and((new TaxEstimator($settings, 2025))->ssMonthStatus(CarbonImmutable::parse('2025-09-01')))->toBe('first_enrolment');
});

it('persists explicitly confirmed estimate settings from the profile form without changing current settings', function () {
    $this->travelTo(CarbonImmutable::parse('2025-05-01'));
    actingAsAdmin();

    Livewire::test(FinanceSettingsPage::class)
        ->callAction('confirmFiscalProfile', historicalProfileInput([
            'vat_exempt' => 'yes',
            'vat_exemption_threshold' => '12345.67',
            'ss_contribution_exempt' => 'no',
        ]))
        ->assertHasNoFormErrors()
        ->assertSee('Base das estimativas históricas')
        ->assertSee(Money::format(1234567));

    $profile = FiscalProfile::query()->sole();

    expect($profile->vat_exempt)->toBeTrue()
        ->and($profile->vat_exemption_threshold)->toBe(1234567)
        ->and($profile->ss_contribution_exempt)->toBeFalse()
        ->and(app(FinanceSettings::class)->vatExempt())->toBeFalse();
});

it('rejects invalid historical inputs without creating a version', function (array $overrides) {
    $actor = actingAsAdmin();

    expect(fn () => app(ConfirmFiscalProfile::class)->handle($actor, historicalProfileInput($overrides)))
        ->toThrow(ValidationException::class);
    expect(FiscalProfile::query()->count())->toBe(0);
})->with([
    'free text in the typed VAT field' => [['vat_exempt' => 'artigo 53']],
    'threshold without confirmed exemption' => [['vat_exemption_threshold' => 10000]],
    'fraction of a cent' => [['vat_exempt' => 'yes', 'vat_exemption_threshold' => 100.25]],
    'unrecognised contribution exemption' => [['ss_contribution_exempt' => 'unknown']],
]);

it('rejects historical setting confirmation by a read-only owner', function () {
    $this->account->update(['billing_exempt' => false, 'trial_ends_at' => now()->subDay()]);
    $owner = User::factory()->create();
    $this->account->users()->attach($owner, ['role' => 'owner']);
    $this->actingAs($owner);

    expect(fn () => app(ConfirmFiscalProfile::class)->handle($owner, historicalProfileInput(['vat_exempt' => 'yes'])))
        ->toThrow(fn (HttpException $exception) => expect($exception->getStatusCode())->toBe(403));
    expect(FiscalProfile::query()->count())->toBe(0);
});
