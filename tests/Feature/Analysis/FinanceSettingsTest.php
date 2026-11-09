<?php

use App\Filament\Pages\FinanceSettingsPage;
use App\Filament\Pages\TaxEstimate;
use App\Support\FinanceSettings;
use Illuminate\Support\Carbon;

it('returns the documented defaults when nothing is stored', function () {
    $settings = app(FinanceSettings::class);

    expect($settings->vatExempt())->toBeFalse()
        ->and($settings->vatExemptionThreshold())->toBe(1500000)
        ->and($settings->ssRate())->toBe(0.214)
        ->and($settings->ssRelevantIncomeShare())->toBe(0.70)
        ->and($settings->ssContributionExempt())->toBeFalse()
        ->and($settings->activityStartedOn())->toBeNull()
        ->and($settings->accountNames())->toBe([])
        ->and($settings->all())->not->toHaveKey('current_bank_balance')
        ->and($settings->all())->not->toHaveKey('ss_first_year_exempt')
        ->and($settings->all())->not->toHaveKey('irs_exempt');
});

it('stores and reads values back with their types', function () {
    $settings = app(FinanceSettings::class);

    $settings->set([
        'vat_exempt' => true,
        'vat_exemption_threshold' => '1450050',
        'activity_started_on' => '2026-02-01',
    ]);

    $fresh = app(FinanceSettings::class);

    expect($fresh->vatExempt())->toBeTrue()
        ->and($fresh->vatExemptionThreshold())->toBe(1450050)
        ->and($fresh->activityStartedOn()->toDateString())->toBe('2026-02-01')
        ->and($fresh->all())->toHaveKey('ss_rate');
});

it('treats payments as not tax-exempt by default, and lets you turn it on on the settings page', function () {
    expect(app(FinanceSettings::class)->paymentsExemptByDefault())->toBeFalse();

    actingAsAdmin();

    Livewire\Livewire::test(FinanceSettingsPage::class)
        ->assertSee('Recebimentos isentos por defeito');
});

it('saves the names of Pedro\'s accounts used to recognise own transfers', function () {
    actingAsAdmin();

    Livewire\Livewire::test(FinanceSettingsPage::class)
        ->fillForm(['account_names' => " Pedro Filipe Pereira Monteiro \n\nOrdenado "])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(FinanceSettings::class)->accountNames())->toBe(['Pedro Filipe Pereira Monteiro', 'Ordenado']);
});

it('refuses a negative VAT limit on the settings page', function () {
    actingAsAdmin();

    Livewire\Livewire::test(FinanceSettingsPage::class)
        ->fillForm(['vat_exempt' => true, 'vat_exemption_threshold' => -1])
        ->call('save')
        ->assertHasFormErrors(['vat_exemption_threshold' => 'min']);

    expect(app(FinanceSettings::class)->vatExemptionThreshold())->toBe(1500000);
});

it('reads the new tax settings with their defaults', function () {
    $settings = app(FinanceSettings::class);

    expect($settings->activityStartKind())->toBeNull()
        ->and($settings->previousActivityEndedOn())->toBeNull()
        ->and($settings->ssEnrolledFrom())->toBeNull()
        ->and($settings->dependentIncomeYears())->toBe([])
        ->and($settings->irsCoefficient())->toBe(0.75)
        ->and($settings->irsJovem())->toBeFalse()
        ->and($settings->birthDate())->toBeNull()
        ->and($settings->irsJovemYearNumber(2026))->toBeNull()
        ->and($settings->ssContributionExempt())->toBeFalse()
        ->and($settings->reverseChargeVatRate())->toBe(0.23);
});

it('counts the IRS Jovem year from the stored reference year', function () {
    app(FinanceSettings::class)->set(['irs_jovem_year' => ['year' => 2026, 'number' => 2]]);

    $settings = app(FinanceSettings::class);

    expect($settings->irsJovemYearNumber(2026))->toBe(2)
        ->and($settings->irsJovemYearNumber(2027))->toBe(3);
});

it('is incomplete until the activity start and kind are set', function () {
    $settings = app(FinanceSettings::class);
    expect($settings->isIncomplete())->toBeTrue();

    $settings->set(['activity_started_on' => '2026-02-01']);
    expect(app(FinanceSettings::class)->isIncomplete())->toBeTrue();

    $settings->set(['activity_start_kind' => 'first']);
    expect(app(FinanceSettings::class)->isIncomplete())->toBeFalse();
});

it('saves and reloads every activity, IRS, Segurança Social and VAT setting', function () {
    $this->travelTo(Carbon::parse('2026-11-09'));
    actingAsAdmin();

    Livewire\Livewire::test(FinanceSettingsPage::class)
        ->assertSee('Tive salário ou pensão em 2026')
        ->assertSee('Tive salário ou pensão em 2025')
        ->assertSee('Consultar e alterar os dados de atividade')
        ->assertSee('programadores, designers, consultores')
        ->assertSee('art. 157.º')
        ->fillForm([
            'activity_started_on' => '2026-02-01',
            'activity_start_kind' => 'restart',
            'previous_activity_ended_on' => '2019-12-31',
            'ss_enrolled_from' => '2026-03-01',
            'dependent_income_current_year' => true,
            'dependent_income_previous_year' => false,
            'irs_coefficient' => '0.35',
            'irs_jovem' => true,
            'birth_date' => '1998-05-20',
            'irs_jovem_year_number' => 3,
            'ss_rate' => '0.252',
            'ss_relevant_income_share_percent' => 70,
            'ss_contribution_exempt' => true,
            'reverse_charge_vat_rate_percent' => 22,
        ])
        ->assertSee('Em 2026, és no ano n.º')
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(FinanceSettings::class);

    expect($settings->activityStartedOn()->toDateString())->toBe('2026-02-01')
        ->and($settings->activityStartKind())->toBe('restart')
        ->and($settings->previousActivityEndedOn()->toDateString())->toBe('2019-12-31')
        ->and($settings->ssEnrolledFrom()->toDateString())->toBe('2026-03-01')
        ->and($settings->dependentIncomeYears())->toBe([2026])
        ->and($settings->irsCoefficient())->toBe(0.35)
        ->and($settings->irsJovem())->toBeTrue()
        ->and($settings->birthDate()->toDateString())->toBe('1998-05-20')
        ->and($settings->all()['irs_jovem_year'])->toBe(['year' => 2026, 'number' => 3])
        ->and($settings->ssRate())->toBe(0.252)
        ->and($settings->ssContributionExempt())->toBeTrue()
        ->and($settings->reverseChargeVatRate())->toBe(0.22);

    Livewire\Livewire::test(FinanceSettingsPage::class)
        ->assertSchemaStateSet([
            'activity_started_on' => '2026-02-01',
            'activity_start_kind' => 'restart',
            'previous_activity_ended_on' => '2019-12-31',
            'ss_enrolled_from' => '2026-03-01',
            'dependent_income_current_year' => true,
            'dependent_income_previous_year' => false,
            'irs_coefficient' => '0.35',
            'irs_jovem' => true,
            'birth_date' => '1998-05-20',
            'irs_jovem_year_number' => 3,
            'ss_rate' => '0.252',
            'ss_contribution_exempt' => true,
            'reverse_charge_vat_rate_percent' => 22.0,
        ], 'form');
});

it('keeps the older salary years when saving the two shown on the settings page', function () {
    $this->travelTo(Carbon::parse('2026-11-09'));
    actingAsAdmin();
    app(FinanceSettings::class)->set(['dependent_income_years' => [2020, 2025, 2026]]);

    Livewire\Livewire::test(FinanceSettingsPage::class)
        ->assertSchemaStateSet(['dependent_income_current_year' => true, 'dependent_income_previous_year' => true], 'form')
        ->fillForm(['dependent_income_current_year' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(FinanceSettings::class)->dependentIncomeYears())->toBe([2020, 2025]);
});

it('derives this year\'s IRS Jovem number from the stored reference year', function () {
    $this->travelTo(Carbon::parse('2026-11-09'));
    actingAsAdmin();
    app(FinanceSettings::class)->set(['irs_jovem' => true, 'birth_date' => '1998-05-20', 'irs_jovem_year' => ['year' => 2024, 'number' => 1]]);

    Livewire\Livewire::test(FinanceSettingsPage::class)
        ->assertSchemaStateSet(['irs_jovem_year_number' => 3], 'form')
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(FinanceSettings::class)->all()['irs_jovem_year'])->toBe(['year' => 2026, 'number' => 3]);
});

it('leaves the IRS Jovem number blank once the 10 years are over', function () {
    $this->travelTo(Carbon::parse('2026-11-09'));
    actingAsAdmin();
    app(FinanceSettings::class)->set(['irs_jovem' => true, 'birth_date' => '1998-05-20', 'irs_jovem_year' => ['year' => 2015, 'number' => 1]]);

    Livewire\Livewire::test(FinanceSettingsPage::class)
        ->assertSchemaStateSet(['irs_jovem_year_number' => null], 'form')
        ->assertSee('O IRS Jovem dura 10 anos: já terminou')
        ->fillForm(['irs_jovem' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(FinanceSettings::class)->irsJovem())->toBeFalse();
});

it('asks for the birth date with IRS Jovem but not for the cessation date of a restart', function () {
    actingAsAdmin();

    // An account that has not said how it started is not assumed a first start.
    Livewire\Livewire::test(FinanceSettingsPage::class)
        ->assertSchemaStateSet(['activity_start_kind' => null], 'form')
        ->assertFormFieldHidden('previous_activity_ended_on')
        ->assertFormFieldHidden('birth_date')
        ->fillForm(['activity_start_kind' => 'restart', 'irs_jovem' => true, 'previous_activity_ended_on' => null, 'birth_date' => null])
        ->assertFormFieldVisible('previous_activity_ended_on')
        ->assertFormFieldVisible('birth_date')
        ->assertSee('Sem esta data não aplicamos a redução')
        ->call('save')
        ->assertHasFormErrors(['birth_date' => 'required', 'irs_jovem_year_number' => 'required'])
        ->assertHasNoFormErrors(['previous_activity_ended_on']);
});

it('says where to find each fiscal answer in the settings', function () {
    actingAsAdmin();

    Livewire\Livewire::test(FinanceSettingsPage::class)
        ->assertSee('Onde ver?')
        ->assertSee('Declarações de Atividade')
        ->assertSee('Situação atual')
        ->assertSee('Enquadramento em IVA');
});

it('estimates Pedro\'s account from his settings: exempt from SS, IRS at 0,75 without reduction', function () {
    $this->travelTo(Carbon::parse('2026-11-09'));
    actingAsAdmin();

    Livewire\Livewire::test(FinanceSettingsPage::class)
        ->fillForm([
            'activity_started_on' => '2025-08-07',
            'activity_start_kind' => 'restart',
            'previous_activity_ended_on' => '2012-06-30',
            'ss_enrolled_from' => '2025-08-01',
            'ss_rate' => '0.214',
            'ss_contribution_exempt' => true,
            'dependent_income_current_year' => true,
            'irs_coefficient' => '0.75',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    Livewire\Livewire::test(TaxEstimate::class)
        ->assertSee('Isento (art. 157.º).')
        ->assertSee('Coeficiente 0,75 (sem redução: ano com salário ou pensão)')
        ->assertDontSee('Definições fiscais incompletas');
});
