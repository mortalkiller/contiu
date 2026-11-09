<?php

use App\Filament\Pages\FinanceSettingsPage;
use App\Support\FinanceSettings;

it('returns the documented defaults when nothing is stored', function () {
    $settings = app(FinanceSettings::class);

    expect($settings->irsExempt())->toBeFalse()
        ->and($settings->vatExempt())->toBeFalse()
        ->and($settings->vatExemptionThreshold())->toBe(1500000)
        ->and($settings->ssRate())->toBe(0.214)
        ->and($settings->ssRelevantIncomeShare())->toBe(0.70)
        ->and($settings->ssFirstYearExempt())->toBeFalse()
        ->and($settings->activityStartedOn())->toBeNull()
        ->and($settings->accountNames())->toBe([])
        ->and($settings->all())->not->toHaveKey('current_bank_balance');
});

it('stores and reads values back with their types', function () {
    $settings = app(FinanceSettings::class);

    $settings->set([
        'irs_exempt' => false,
        'vat_exemption_threshold' => '1450050',
        'activity_started_on' => '2026-02-01',
    ]);

    $fresh = app(FinanceSettings::class);

    expect($fresh->irsExempt())->toBeFalse()
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
