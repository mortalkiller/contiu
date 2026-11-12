<?php

use App\Enums\ExpenseAttribution;
use App\Enums\FiscalDocumentType;
use App\Filament\Analysis\Widgets\TaxQuartersTable;
use App\Filament\Pages\FinanceSettingsPage;
use App\Filament\Resources\FiscalDocuments\Schemas\FiscalDocumentDraftForm;
use App\Models\Expense;
use App\Models\FiscalDocument;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Support\FinanceSettings;
use App\Support\IrsPrepayments;
use App\Support\PortugueseDeadline;
use App\Support\TaxEstimator;
use App\Support\TaxObligation;
use App\Support\TaxObligations;
use App\Support\WithholdingReview;
use App\Support\WithholdingWaiver;
use App\Support\YearlyParameter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
 * The rules of docs/fiscal/memoria-fiscal-ti-eni-portugal-2026.md that the tax estimate and the
 * obligations calendar apply (rule IDs in the test names).
 */

pest()->group('integration', 'fiscal');

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-11-06')));

/** A payment received on $date for a project charged with $vat. */
function rulesPaid(string $date, int $amount, float $vat = 0): Payment
{
    $project = Project::factory()->create(['net_amount' => $amount, 'vat_rate' => $vat, 'quoted_on' => $date]);
    $receivable = Receivable::factory()->for($project, 'billable')->create(['amount' => $amount, 'due_on' => $date]);

    return Payment::factory()->for($receivable)->create(['paid_on' => $date, 'amount' => $amount]);
}

/** The estimate of an account whose activity started long ago, with $settings on top. */
function rulesEstimator(int $year, array $settings = []): TaxEstimator
{
    app(FinanceSettings::class)->set(['activity_started_on' => '2020-01-01', 'activity_start_kind' => 'first', ...$settings]);

    return new TaxEstimator(app(FinanceSettings::class), $year);
}

/** The obligations seen on $today by an account created on $since, with $settings on top. */
function rulesObligations(string $today, array $settings = [], ?string $since = null): TaxObligations
{
    $today = CarbonImmutable::parse($today);
    test()->travelTo(Carbon::parse($today));
    test()->account->forceFill(['created_at' => $since ?? $today->startOfMonth(), 'billing_exempt' => true])->save();
    app(FinanceSettings::class)->set(['activity_started_on' => '2020-01-01', 'activity_start_kind' => 'first', ...$settings]);

    return new TaxObligations(new FinanceSettings, $today);
}

/** @return array<string, TaxObligation> */
function rulesByKey(array $obligations): array
{
    return collect($obligations)->keyBy('key')->all();
}

it('moves deadlines off weekends and national holidays, and ends August ones on the 31st (OBR03, SS061)', function () {
    $deadline = fn (string $date): string => PortugueseDeadline::of(CarbonImmutable::parse($date))->toDateString();

    expect($deadline('2026-11-20'))->toBe('2026-11-20')
        // Saturday, then 1 November (a Sunday and a holiday).
        ->and($deadline('2026-10-31'))->toBe('2026-11-02')
        ->and($deadline('2026-12-08'))->toBe('2026-12-09')
        // Corpus Christi, sixty days after Easter.
        ->and($deadline('2026-06-04'))->toBe('2026-06-05')
        // August ends on the 31st, even on a Sunday.
        ->and($deadline('2025-08-20'))->toBe('2025-08-31');
});

it('reads yearly values by the year they apply to, and flags a missing year as provisional', function () {
    expect(YearlyParameter::ias(2024))->toBe(50926)
        ->and(YearlyParameter::vatExemptionLimit(2023))->toBe(1350000)
        ->and(YearlyParameter::vatExemptionTolerance(2024))->toBeNull()
        ->and(YearlyParameter::vatExemptionTolerance(2026))->toBe(1875000)
        ->and(YearlyParameter::irsSpecificDeduction(2026))->toBe(458709)
        ->and(YearlyParameter::ias(2027))->toBe(53713)
        ->and(YearlyParameter::isProvisional('ias', 2027))->toBeTrue();
});

it('leaves the VAT and adds the withheld IRS to the income (SS021)', function () {
    rulesPaid('2026-02-10', 123000, 0.23);
    $payment = Payment::factory()->create(['amount' => 8850, 'paid_on' => '2026-03-02']);
    $receipt = FiscalDocument::factory()->create([
        'type' => FiscalDocumentType::Receipt, 'document_date' => '2026-03-02',
        'net_amount' => 10000, 'vat_amount' => 0, 'total_amount' => 10000,
    ]);
    $receipt->links()->create(['payment_id' => $payment->id]);
    WithholdingReview::record($receipt->id, 1150);

    // 1 230 € with VAT is 1 000 €; 88,50 € received plus 11,50 € withheld is 100 €.
    expect(rulesEstimator(2026)->income())->toBe(110000);
});

it('counts 20% of sales of goods for Social Security and gives them no first-year reduction (SS020, INI001)', function () {
    rulesPaid('2026-02-10', 300000);

    $goods = rulesEstimator(2026, ['irs_coefficient' => 0.15]);

    expect($goods->ssRelevantMonthlyIncome(CarbonImmutable::parse('2026-01-01')))->toBe(20000)
        ->and($goods->irsContributionsDeduction())->toBe(0)
        ->and($goods->irsExpenseShortfall())->toBe(0);

    $newShop = rulesEstimator(2026, ['irs_coefficient' => 0.15, 'activity_started_on' => '2026-01-05']);

    expect($newShop->irsCoefficientReduction())->toBe(0.0)
        ->and($newShop->effectiveIrsCoefficient())->toBe(0.15);
});

it('charges an accumulation exemption on the excess over 4 IAS, and only that one (SS040, SS042)', function () {
    // 10 000 € in the first quarter: 2 333,33 € of relevant income a month, 184,81 € above 4 IAS.
    rulesPaid('2026-02-10', 1000000);

    $accumulation = rulesEstimator(2026, ['ss_contribution_exempt' => true, 'ss_exemption_reason' => 'accumulation']);

    expect($accumulation->ssMonth(CarbonImmutable::parse('2026-04-01')))->toBe(['status' => 'charged', 'base' => 18481, 'contribution' => 3955])
        ->and($accumulation->ssMonthStatus(CarbonImmutable::parse('2026-07-01')))->toBe('exempt')
        ->and(rulesEstimator(2026, ['ss_contribution_exempt' => true, 'ss_exemption_reason' => 'pension'])->ssMonthStatus(CarbonImmutable::parse('2026-04-01')))->toBe('exempt');
});

it('still asks for the declaration when the excess contribution is under €5 (SS042, SS045)', function () {
    rulesPaid('2026-02-10', 925000);

    $rows = rulesByKey(rulesObligations('2026-05-11', ['ss_contribution_exempt' => true, 'ss_exemption_reason' => 'accumulation', 'vat_exempt' => true], since: '2026-04-01')->thisMonth());

    expect($rows)->toHaveKey('ss-declaration:2026-Q1')
        ->and($rows['ss:2026-05'])->toMatchObject(['status' => 'exempt', 'amount' => null])
        ->and($rows['ss:2026-05']->detail)->toContain('menos de 5 €');
});

it('leaves an excess contribution that is €5 only after rounding to be confirmed (SS042, SSC06)', function () {
    // 9 308,05 € in the first quarter: 2 171,88 € a month, 23,36 € above 4 IAS, 4,99904 € at 21,4 %.
    rulesPaid('2026-02-10', 930805);

    $settings = ['ss_contribution_exempt' => true, 'ss_exemption_reason' => 'accumulation', 'vat_exempt' => true];
    $rows = rulesByKey(rulesObligations('2026-05-11', $settings, since: '2026-04-01')->thisMonth());

    expect(rulesEstimator(2026, $settings)->ssMonth(CarbonImmutable::parse('2026-04-01')))->toBe(['status' => 'charged', 'base' => 2336, 'contribution' => 0, 'toConfirm' => true])
        ->and($rows)->toHaveKey('ss-declaration:2026-Q1')
        ->and($rows['ss:2026-05'])->toMatchObject(['status' => 'exempt', 'amount' => null])
        ->and($rows['ss:2026-05']->detail)->toContain('A confirmar na Segurança Social Direta');
});

it('flags the IRS Jovem cap as shared in a year with a salary (JOV005, IRS09)', function () {
    $young = ['irs_jovem' => true, 'irs_jovem_year' => ['year' => 2026, 'number' => 2], 'birth_date' => '1997-05-14'];

    expect(rulesEstimator(2026, [...$young, 'dependent_income_years' => [2026]])->irsJovemCapSharedWithSalary())->toBeTrue()
        ->and(rulesEstimator(2026, [...$young, 'dependent_income_years' => [2025]])->irsJovemCapSharedWithSalary())->toBeFalse()
        ->and(rulesEstimator(2026, ['irs_jovem' => false, 'dependent_income_years' => [2026]])->irsJovemCapSharedWithSalary())->toBeFalse();
});

it('caps the base at 12 IAS and applies the chosen adjustment (SS032, SS033)', function () {
    rulesPaid('2026-02-10', 300000);

    expect(rulesEstimator(2026, ['ss_base_adjustment' => 25])->ssMonth(CarbonImmutable::parse('2026-04-01')))->toBe(['status' => 'charged', 'base' => 87500, 'contribution' => 18725])
        ->and(rulesEstimator(2026, ['ss_base_adjustment' => -25])->ssMonth(CarbonImmutable::parse('2026-04-01'))['base'])->toBe(52500);

    rulesPaid('2026-03-10', 9700000);

    expect(rulesEstimator(2026)->ssMonth(CarbonImmutable::parse('2026-04-01')))->toBe(['status' => 'charged', 'base' => 644556, 'contribution' => 137935]);
});

it('charges the minimum until the first declaration and leaves out income from before the enrolment (SS015, SS051)', function () {
    // Started on 10 March 2025: the enrolment takes effect on 1 March 2026.
    rulesPaid('2026-01-15', 1000000);
    rulesPaid('2026-03-10', 300000);

    $estimator = rulesEstimator(2026, ['activity_started_on' => '2025-03-10']);

    expect($estimator->ssMonth(CarbonImmutable::parse('2026-02-01'))['status'])->toBe('first_enrolment')
        ->and($estimator->ssContributionForMonth(CarbonImmutable::parse('2026-03-01')))->toBe(TaxEstimator::SS_MINIMUM_CONTRIBUTION)
        // April's base is March's income only: 3 000 € × 70% ÷ 3.
        ->and($estimator->ssMonth(CarbonImmutable::parse('2026-04-01')))->toBe(['status' => 'charged', 'base' => 70000, 'contribution' => 14980])
        ->and(rulesByKey(rulesObligations('2026-04-15', ['activity_started_on' => '2025-03-10', 'vat_exempt' => true])->thisMonth()))->toHaveKey('ss-declaration:2026-Q1')
        ->and(rulesByKey(rulesObligations('2026-04-15', ['activity_started_on' => '2025-04-10', 'vat_exempt' => true])->thisMonth()))->not->toHaveKey('ss-declaration:2026-Q1');
});

it('adds to the taxable income the expenses missing to reach 15% of the gross income (DES002, DES005)', function () {
    rulesPaid('2026-02-10', 4000000);
    Expense::factory()->create(['spent_on' => '2026-05-01', 'net_amount' => 50000, 'vat_rate' => 0.23, 'attribution' => ExpenseAttribution::Professional]);
    Expense::factory()->create(['spent_on' => '2026-06-01', 'net_amount' => 100000, 'vat_rate' => 0.23, 'attribution' => ExpenseAttribution::Mixed]);

    // Exempt from Social Security: no contributions to deduct nor to count.
    $estimator = rulesEstimator(2026, ['ss_contribution_exempt' => true, 'ss_exemption_reason' => 'other']);

    // 15% of 40 000 € is 6 000 €: 4 587,09 € automatic, 500 € of business use and 25% of the
    // 1 000 € of mixed use (VAT left out, as it is deductible) leave 662,91 € missing.
    expect($estimator->irsEligibleExpenses())->toBe(75000)
        ->and($estimator->irsExpenseShortfall())->toBe(66291)
        ->and($estimator->irsTaxableIncome())->toBe(3000000 + 66291)
        // With the VAT exemption the VAT is a cost and counts.
        ->and(rulesEstimator(2026, ['ss_contribution_exempt' => true, 'ss_exemption_reason' => 'other', 'vat_exempt' => true])->irsEligibleExpenses())->toBe(61500 + 30750)
        // With IRS Jovem the combination is not confirmed (JOV008): no figure.
        ->and(rulesEstimator(2026, ['ss_contribution_exempt' => true, 'ss_exemption_reason' => 'other', 'irs_jovem' => true, 'irs_jovem_year' => ['year' => 2026, 'number' => 3]])->irsExpenseShortfall())->toBeNull();
});

it('lists the periodic VAT return outside the exemption, June and the second quarter in September (IVA26, IVA27)', function () {
    $quarterly = rulesByKey(rulesObligations('2026-11-09')->thisMonth());

    expect($quarterly['vat-return:2026-Q3'])->toMatchObject(['kind' => 'vat-return', 'status' => 'due', 'amount' => null])
        ->and($quarterly['vat-return:2026-Q3']->dueOn->toDateString())->toBe('2026-11-20')
        ->and($quarterly['vat-return:2026-Q3']->detail)->toContain('pagar até dia 25');

    $september = rulesObligations('2026-09-01', ['vat_period' => 'monthly'], since: '2026-01-01')
        ->between(CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-09-30'));
    $returns = collect($september)->where('kind', 'vat-return');

    // 20 September 2026 is a Sunday.
    expect($returns->pluck('key')->all())->toBe(['vat-return:2026-06', 'vat-return:2026-07'])
        ->and($returns->map(fn ($row) => $row->dueOn->toDateString())->unique()->values()->all())->toBe(['2026-09-21'])
        ->and(rulesByKey(rulesObligations('2026-11-09', ['vat_exempt' => true])->thisMonth()))->not->toHaveKey('vat-return:2026-Q3');
});

it('asks in January to confirm the year before only after declaring in it (SS054)', function () {
    expect(rulesByKey(rulesObligations('2027-01-05', ['vat_exempt' => true])->thisMonth()))->toHaveKey('ss-annual:2026')
        ->and(rulesByKey(rulesObligations('2027-01-05', ['vat_exempt' => true, 'ss_contribution_exempt' => true, 'ss_exemption_reason' => 'pension'])->thisMonth()))->not->toHaveKey('ss-annual:2026');
});

it('shows nothing to do while the activity is not open, and no Social Security figures with organised accounting', function () {
    $waiting = rulesObligations('2026-11-09', ['activity_opened' => false]);

    expect(array_keys(rulesByKey($waiting->thisMonth())))->toBe(['activity-not-opened'])
        ->and($waiting->upcoming())->toBe([]);

    $organised = rulesObligations('2026-11-09', ['activity_opened' => true, 'accounting_regime' => 'organised', 'vat_exempt' => true]);
    $rows = rulesByKey($organised->thisMonth());

    expect($rows['ss:2026-11'])->toMatchObject(['status' => 'due', 'amount' => null])
        ->and($rows['ss:2026-11']->detail)->toContain('lucro tributável')
        ->and(rulesByKey($organised->upcoming()))->not->toHaveKey('ss-declaration:2026-Q4');
});

it('asks for the Social Security date after a restart (SS012)', function () {
    expect(rulesByKey(rulesObligations('2026-11-09', ['activity_start_kind' => 'restart', 'vat_exempt' => true])->thisMonth()))->toHaveKey('ss-enrolment-date')
        ->and(rulesByKey(rulesObligations('2026-11-09', ['activity_start_kind' => 'restart', 'ss_enrolled_from' => '2020-01-01', 'vat_exempt' => true])->thisMonth()))->not->toHaveKey('ss-enrolment-date');

    actingAsAdmin();

    Livewire::test(FinanceSettingsPage::class)
        ->fillForm(['activity_start_kind' => 'restart', 'ss_enrolled_from' => null])
        ->call('save')
        ->assertHasFormErrors(['ss_enrolled_from']);
});

it('works out the IRS payments on account from the assessment two years before (PPC001, PPC002)', function () {
    $basis = ['income_year' => 2024, 'tax' => 300000, 'withheld' => 50000, 'net_business' => 2000000, 'net_total' => 2500000];
    app(FinanceSettings::class)->set(['irs_prepayment_basis' => $basis]);

    // 65% × (3 000 € × 80% − 500 €) = 1 235 €, in three of 412 € (rounded up to the euro).
    $installments = (new IrsPrepayments(app(FinanceSettings::class)))->forYear(2026);

    expect(array_column($installments, 'amount'))->toBe([41200, 41200, 41200])
        ->and(array_map(fn (array $installment): string => $installment['dueOn']->toDateString(), array_values($installments)))->toBe(['2026-07-20', '2026-09-21', '2026-12-21'])
        ->and((new IrsPrepayments(app(FinanceSettings::class)))->forYear(2027))->toBe([]);

    app(FinanceSettings::class)->set(['irs_prepayment_basis' => [...$basis, 'tax' => 20000, 'withheld' => 0, 'net_business' => 100, 'net_total' => 100]]);

    // 65% × 200 € ÷ 3 is 43,33 €: under 50 €, not due.
    expect((new IrsPrepayments(app(FinanceSettings::class)))->forYear(2026))->toBe([]);

    app(FinanceSettings::class)->set(['irs_prepayment_basis' => $basis]);

    expect(rulesByKey(rulesObligations('2026-07-10', ['irs_prepayment_basis' => $basis, 'vat_exempt' => true])->thisMonth())['irs-ppc:2026-1']->amount)->toBe(41200);
});

it('ends the withholding waiver the month after the income reaches the limit (RET004, RET005)', function () {
    rulesPaid('2026-03-10', 1500000);
    app(FinanceSettings::class)->set(['activity_started_on' => '2020-01-01', 'activity_start_kind' => 'first', 'withholding_waiver' => true]);
    $waiver = new WithholdingWaiver(app(FinanceSettings::class));

    expect($waiver->endsOn(2026)?->toDateString())->toBe('2026-04-01')
        ->and($waiver->appliesOn(CarbonImmutable::parse('2026-03-15')))->toBeTrue()
        ->and($waiver->appliesOn(CarbonImmutable::parse('2026-04-02')))->toBeFalse()
        ->and(rulesByKey(rulesObligations('2026-11-09', ['withholding_waiver' => true, 'vat_exempt' => true])->thisMonth()))->toHaveKey('withholding-waiver-ended:2026');

    $withMention = FiscalDocumentDraftForm::data(['document_date' => '2026-03-15', 'withholding_basis_points' => 0, 'notes' => 'Obrigado'], FiscalDocumentType::Invoice);
    $withholding = FiscalDocumentDraftForm::data(['document_date' => '2026-03-15', 'withholding_basis_points' => 2300, 'notes' => 'Obrigado'], FiscalDocumentType::Invoice);

    expect($withMention['notes'])->toBe("Obrigado\n".WithholdingWaiver::MENTION)
        ->and($withholding['notes'])->toBe('Obrigado');
});

it('does not allow the waiver after a year at the limit', function () {
    rulesPaid('2025-06-10', 1500000);
    app(FinanceSettings::class)->set(['activity_started_on' => '2020-01-01', 'activity_start_kind' => 'first', 'withholding_waiver' => true]);

    expect((new WithholdingWaiver(app(FinanceSettings::class)))->appliesOn(CarbonImmutable::parse('2026-01-10')))->toBeFalse();
});

it('saves the regime, the activity state and the other new tax settings', function () {
    actingAsAdmin();
    app(FinanceSettings::class)->set(['activity_started_on' => '2020-01-01', 'activity_start_kind' => 'first']);

    Livewire::test(FinanceSettingsPage::class)
        ->fillForm([
            'activity_opened' => false,
            'accounting_regime' => 'organised',
            'ss_base_adjustment' => '10',
            'vat_period' => 'monthly',
            'withholding_waiver' => true,
            'irs_prepayment' => ['tax' => 3000, 'withheld' => 500, 'net_business' => 20000, 'net_total' => 25000],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = new FinanceSettings;

    expect($settings->activityOpened())->toBeFalse()
        ->and($settings->accountingRegime())->toBe('organised')
        ->and($settings->ssBaseAdjustment())->toBe(0.1)
        ->and($settings->vatPeriodMonths())->toBe(1)
        ->and($settings->withholdingWaiver())->toBeTrue()
        ->and($settings->irsPrepaymentBasis())->toBe(['income_year' => 2024, 'tax' => 300000, 'withheld' => 50000, 'net_business' => 2000000, 'net_total' => 2500000]);
})->group('ui');

it('does not ask for the quarterly declaration of an exempt account in the quarters table (SS051)', function () {
    actingAsAdmin();
    app(FinanceSettings::class)->set(['activity_started_on' => '2020-01-01', 'activity_start_kind' => 'first', 'ss_contribution_exempt' => true, 'ss_exemption_reason' => 'pension']);

    Livewire::test(TaxQuartersTable::class, ['year' => 2026])
        ->assertSee('Não é preciso')
        ->assertDontSee('Atrasada');
})->group('ui');
