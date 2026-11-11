<?php

use App\Filament\Pages\FinanceSettingsPage;
use App\Filament\Pages\TaxEstimate;
use App\Models\Account;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

pest()->group('fiscal');

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
})->group('integration');

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
})->group('integration');

it('treats payments as not tax-exempt by default, and lets you turn it on on the settings page', function () {
    expect(app(FinanceSettings::class)->paymentsExemptByDefault())->toBeFalse();

    actingAsAdmin();

    Livewire::test(FinanceSettingsPage::class)
        ->assertSee('Recebimentos isentos por defeito');
})->group('ui');

it('saves the names of Pedro\'s accounts used to recognise own transfers', function () {
    actingAsAdmin();

    Livewire::test(FinanceSettingsPage::class)
        ->fillForm(['account_names' => " Pedro Filipe Pereira Monteiro \n\nOrdenado "])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(FinanceSettings::class)->accountNames())->toBe(['Pedro Filipe Pereira Monteiro', 'Ordenado']);
})->group('ui');

it('refuses a negative VAT limit on the settings page', function () {
    actingAsAdmin();

    Livewire::test(FinanceSettingsPage::class)
        ->fillForm(['vat_exempt' => true, 'vat_exemption_threshold' => -1])
        ->call('save')
        ->assertHasFormErrors(['vat_exemption_threshold' => 'min']);

    expect(app(FinanceSettings::class)->vatExemptionThreshold())->toBe(1500000);
})->group('ui');

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
})->group('integration');

it('counts the IRS Jovem year from the stored reference year', function () {
    app(FinanceSettings::class)->set(['irs_jovem_year' => ['year' => 2026, 'number' => 2]]);

    $settings = app(FinanceSettings::class);

    expect($settings->irsJovemYearNumber(2026))->toBe(2)
        ->and($settings->irsJovemYearNumber(2027))->toBe(3);
})->group('integration');

it('is incomplete until the activity start and kind are set', function () {
    $settings = app(FinanceSettings::class);
    expect($settings->isIncomplete())->toBeTrue();

    $settings->set(['activity_started_on' => '2026-02-01']);
    expect(app(FinanceSettings::class)->isIncomplete())->toBeTrue();

    $settings->set(['activity_start_kind' => 'first']);
    expect(app(FinanceSettings::class)->isIncomplete())->toBeFalse();
})->group('integration');

it('saves and reloads every activity, IRS, Segurança Social and VAT setting', function () {
    $this->travelTo(Carbon::parse('2026-11-09'));
    actingAsAdmin();

    Livewire::test(FinanceSettingsPage::class)
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
            'ss_contribution_exempt' => true,
            'ss_exemption_reason' => 'accumulation',
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

    Livewire::test(FinanceSettingsPage::class)
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
            'ss_exemption_reason' => 'accumulation',
            'reverse_charge_vat_rate_percent' => 22.0,
        ], 'form');
})->group('ui');

it('keeps the older salary years when saving the two shown on the settings page', function () {
    $this->travelTo(Carbon::parse('2026-11-09'));
    actingAsAdmin();
    app(FinanceSettings::class)->set(['dependent_income_years' => [2020, 2025, 2026]]);

    Livewire::test(FinanceSettingsPage::class)
        ->assertSchemaStateSet(['dependent_income_current_year' => true, 'dependent_income_previous_year' => true], 'form')
        ->fillForm(['dependent_income_current_year' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(FinanceSettings::class)->dependentIncomeYears())->toBe([2020, 2025]);
})->group('ui');

it('derives this year\'s IRS Jovem number from the stored reference year', function () {
    $this->travelTo(Carbon::parse('2026-11-09'));
    actingAsAdmin();
    app(FinanceSettings::class)->set(['irs_jovem' => true, 'birth_date' => '1998-05-20', 'irs_jovem_year' => ['year' => 2024, 'number' => 1]]);

    Livewire::test(FinanceSettingsPage::class)
        ->assertSchemaStateSet(['irs_jovem_year_number' => 3], 'form')
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(FinanceSettings::class)->all()['irs_jovem_year'])->toBe(['year' => 2026, 'number' => 3]);
})->group('ui');

it('leaves the IRS Jovem number blank once the 10 years are over', function () {
    $this->travelTo(Carbon::parse('2026-11-09'));
    actingAsAdmin();
    app(FinanceSettings::class)->set(['irs_jovem' => true, 'birth_date' => '1998-05-20', 'irs_jovem_year' => ['year' => 2015, 'number' => 1]]);

    Livewire::test(FinanceSettingsPage::class)
        ->assertSchemaStateSet(['irs_jovem_year_number' => null], 'form')
        ->assertSee('O IRS Jovem dura 10 anos: já terminou')
        ->fillForm(['irs_jovem' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(FinanceSettings::class)->irsJovem())->toBeFalse();
})->group('ui');

it('asks for the birth date with IRS Jovem but not for the cessation date of a restart', function () {
    actingAsAdmin();

    // An account that has not said how it started is not assumed a first start.
    Livewire::test(FinanceSettingsPage::class)
        ->assertSchemaStateSet(['activity_start_kind' => null], 'form')
        ->assertSeeHtml("x-bind:disabled=\"! (\$get('activity_start_kind') === 'restart')\"")
        ->assertSeeHtml("x-bind:disabled=\"! (!! \$get('irs_jovem'))\"")
        ->fillForm(['activity_start_kind' => 'restart', 'irs_jovem' => true, 'previous_activity_ended_on' => null, 'birth_date' => null])
        ->assertSee('Sem esta data não aplicamos a redução')
        ->call('save')
        ->assertHasFormErrors(['birth_date' => 'required', 'irs_jovem_year_number' => 'required'])
        ->assertHasNoFormErrors(['previous_activity_ended_on']);
})->group('ui');

it('says where to find each fiscal answer in the settings', function () {
    actingAsAdmin();

    Livewire::test(FinanceSettingsPage::class)
        ->assertSee('Onde ver?')
        ->assertSee('Declarações de Atividade')
        ->assertSee('Situação atual')
        ->assertSee('Enquadramento em IVA');
})->group('ui');

it('estimates Pedro\'s account from his settings: exempt from SS, IRS at 0,75 without reduction', function () {
    $this->travelTo(Carbon::parse('2026-11-09'));
    actingAsAdmin();

    Livewire::test(FinanceSettingsPage::class)
        ->fillForm([
            'activity_started_on' => '2025-08-07',
            'activity_start_kind' => 'restart',
            'previous_activity_ended_on' => '2012-06-30',
            'ss_enrolled_from' => '2025-08-01',
            'ss_rate' => '0.214',
            'ss_contribution_exempt' => true,
            'ss_exemption_reason' => 'accumulation',
            'dependent_income_current_year' => true,
            'irs_coefficient' => '0.75',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    Livewire::test(TaxEstimate::class)
        ->assertSee('Isento (art. 157.º).')
        ->assertSee('Coeficiente 0,75 (sem redução: ano com salário ou pensão)')
        ->assertDontSee('Definições fiscais incompletas');
})->group('ui');

it('saves from when the obligations are tracked', function () {
    $this->travelTo(\Carbon\Carbon::parse('2026-11-09'));
    actingAsAdmin();

    Livewire::test(FinanceSettingsPage::class)
        ->assertSchemaStateSet(['obligations_tracked_from' => null], 'form')
        ->fillForm(['obligations_tracked_from' => '2025-08-01'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(FinanceSettings::class)->obligationsTrackedFrom()?->toDateString())->toBe('2025-08-01');

    Livewire::test(FinanceSettingsPage::class)
        ->assertSchemaStateSet(['obligations_tracked_from' => '2025-08-01'], 'form');
})->group('ui');

it('shares fiscal settings during the request and keeps them isolated by account', function () {
    $account = CurrentAccount::get();
    $other = Account::factory()->create();
    $settings = app(FinanceSettings::class);

    expect(app(FinanceSettings::class))->toBe($settings);

    $settings->set(['vat_exempt' => true]);

    CurrentAccount::run($other, function () use ($settings): void {
        expect($settings->vatExempt())->toBeFalse();
        $settings->set(['vat_exemption_threshold' => 1250000]);

        expect(app(FinanceSettings::class)->vatExemptionThreshold())->toBe(1250000);
    });

    expect(CurrentAccount::get())->toBe($account)
        ->and($settings->vatExempt())->toBeTrue()
        ->and($settings->vatExemptionThreshold())->toBe(1500000);
})->group('integration');

describe('Fiscal settings form', function () {
    beforeEach(function () {
        $this->travelTo(Carbon::parse('2026-11-06'));
        actingAsAdmin();
    });

    it('shows the fiscal settings with their defaults and saves changes', function () {
        app(FinanceSettings::class)->set(['vat_exempt' => true]); // the threshold only shows while VAT-exempt

        Livewire::test(FinanceSettingsPage::class)
            ->assertSee('confirma os valores com o teu contabilista')
            ->assertSee('Limite anual de isenção de IVA (art. 53.º)')
            ->assertFormFieldDoesNotExist('ss_first_year_exempt')
            ->assertFormFieldDoesNotExist('irs_exempt')
            ->assertDontSee('Isento de IRS')
            ->assertSee('Trabalhador independente (21,4 %)')
            ->assertSee('Empresário em nome individual ou EIRL (25,2 %)')
            ->assertSchemaStateSet(['vat_exempt' => true, 'ss_rate' => '0.214', 'vat_exemption_threshold' => 15000.0, 'reverse_charge_vat_rate_percent' => 23.0], 'form')
            ->fillForm(['ss_rate' => '0.252'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(app(FinanceSettings::class)->ssRate())->toBe(0.252);
    })->group('ui');
});

describe('Fiscal profile summary', function () {
    beforeEach(function () {
        $this->travelTo(Carbon::parse('2026-11-10 12:00'));
        actingAsAdmin();
    });

    it('says the fiscal profile is unconfirmed and that saving recalculates the estimates', function () {
        Livewire::test(FinanceSettingsPage::class)
            ->assertSee('Por confirmar. A estimativa usa as definições abaixo até confirmares uma versão.')
            ->assertSee('Mudar estas definições recalcula a estimativa de impostos e a previsão de tesouraria.');
    })->group('ui');
});
