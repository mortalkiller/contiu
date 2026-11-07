<?php

use App\Filament\Pages\FinanceSettingsPage;
use App\Support\FinanceSettings;

it('returns the documented defaults when nothing is stored', function () {
    $settings = app(FinanceSettings::class);

    expect($settings->irsExempt())->toBeTrue()
        ->and($settings->vatExempt())->toBeTrue()
        ->and($settings->vatExemptionThreshold())->toBe(1500000)
        ->and($settings->ssRate())->toBe(0.214)
        ->and($settings->ssRelevantIncomeShare())->toBe(0.70)
        ->and($settings->ssFirstYearExempt())->toBeTrue()
        ->and($settings->activityStartedOn())->toBeNull()
        ->and($settings->currentBankBalance())->toBeNull()
        ->and($settings->currentBankBalanceOn())->toBeNull();
});

it('stores and reads values back with their types', function () {
    $settings = app(FinanceSettings::class);

    $settings->set([
        'irs_exempt' => false,
        'vat_exemption_threshold' => '1450050',
        'activity_started_on' => '2026-02-01',
        'current_bank_balance' => '321040',
        'current_bank_balance_on' => '2026-11-06',
    ]);

    $fresh = app(FinanceSettings::class);

    expect($fresh->irsExempt())->toBeFalse()
        ->and($fresh->vatExemptionThreshold())->toBe(1450050)
        ->and($fresh->activityStartedOn()->toDateString())->toBe('2026-02-01')
        ->and($fresh->currentBankBalance())->toBe(321040)
        ->and($fresh->currentBankBalanceOn()->toDateString())->toBe('2026-11-06')
        ->and($fresh->all())->toHaveKey('ss_rate');
});

it('treats payments as tax-exempt by default, and lets you turn it off on the settings page', function () {
    expect(app(FinanceSettings::class)->paymentsExemptByDefault())->toBeTrue();

    actingAsAdmin();

    Livewire\Livewire::test(FinanceSettingsPage::class)
        ->assertSee('Recebimentos isentos por defeito');
});

it('saves the account holder name used to recognise own transfers', function () {
    actingAsAdmin();

    Livewire\Livewire::test(FinanceSettingsPage::class)
        ->fillForm(['account_holder_name' => ' Pedro Filipe Pereira Monteiro '])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(FinanceSettings::class)->accountHolderName())->toBe('Pedro Filipe Pereira Monteiro');
});
