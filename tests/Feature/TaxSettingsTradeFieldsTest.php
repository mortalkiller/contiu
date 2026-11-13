<?php

use App\Enums\FiscalAccountType;
use App\Filament\Pages\FinanceSettingsPage;
use App\Filament\Support\TaxSettingsFields;
use App\Models\Account;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;
use Livewire\Livewire;

pest()->group('fiscal', 'ui');

/*
 * The activity codes (main and secondary) and "I sell or transport goods" on the tax settings page: the second is what
 * offers transport guides.
 */

it('saves the main CAE and whether goods are sold from the settings page', function () {
    actingAsAdmin();

    Livewire::test(FinanceSettingsPage::class)
        ->assertSchemaStateSet(['cae' => null, 'sells_goods' => false])
        ->fillForm(['cae' => ' 47910 ', 'sells_goods' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(FinanceSettings::class);

    expect($settings->cae())->toBe('47910')
        ->and($settings->sellsGoods())->toBeTrue();

    Livewire::test(FinanceSettingsPage::class)
        ->assertSchemaStateSet(['cae' => '47910', 'sells_goods' => true])
        ->fillForm(['cae' => '', 'sells_goods' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(FinanceSettings::class)->cae())->toBeNull()
        ->and(app(FinanceSettings::class)->sellsGoods())->toBeFalse();
});

it('refuses a CAE longer than 64 characters', function () {
    actingAsAdmin();

    Livewire::test(FinanceSettingsPage::class)
        ->fillForm(['cae' => str_repeat('9', 65)])
        ->call('save')
        ->assertHasFormErrors(['cae' => 'max']);

    expect(app(FinanceSettings::class)->cae())->toBeNull();
});

it('saves the secondary CAEs from the settings page', function () {
    actingAsAdmin();

    Livewire::test(FinanceSettingsPage::class)
        ->assertSchemaStateSet(['secondary_caes' => []])
        ->fillForm(['cae' => '62100', 'secondary_caes' => ['63100', '58210']])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(FinanceSettings::class)->secondaryCaes())->toBe(['63100', '58210']);

    Livewire::test(FinanceSettingsPage::class)
        ->fillForm(['secondary_caes' => ['62100']])
        ->call('save')
        ->assertHasFormErrors(['secondary_caes']);
});

it('keeps a main CAE saved before CAE-Rev.4 but refuses a new one off the list', function () {
    actingAsAdmin();
    app(FinanceSettings::class)->set(['cae' => '62010']);

    Livewire::test(FinanceSettingsPage::class)
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(FinanceSettings::class)->cae())->toBe('62010');

    Livewire::test(FinanceSettingsPage::class)
        ->fillForm(['cae' => '12345'])
        ->call('save')
        ->assertHasFormErrors(['cae']);
});

it('saves the fiscal activity type per account without changing its regime or contribution rate', function () {
    actingAsAdmin();
    $settings = app(FinanceSettings::class);
    $settings->set(['ss_rate' => 0.214, 'accounting_regime' => 'simplified']);

    Livewire::test(FinanceSettingsPage::class)
        ->assertSchemaStateSet(['fiscal_account_type' => null])
        ->fillForm(['fiscal_account_type' => 'eni'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($settings->fiscalAccountType())->toBe(FiscalAccountType::SoleTrader)
        ->and($settings->ssRate())->toBe(0.214)
        ->and($settings->accountingRegime())->toBe('simplified');

    CurrentAccount::run(Account::factory()->create(), fn () => expect($settings->fiscalAccountType())->toBeNull());

    Livewire::test(FinanceSettingsPage::class)
        ->assertSchemaStateSet(['fiscal_account_type' => 'eni'])
        ->fillForm(['fiscal_account_type' => 'accountant'])
        ->call('save')
        ->assertHasFormErrors(['fiscal_account_type']);

    expect($settings->fiscalAccountType())->toBe(FiscalAccountType::SoleTrader);
});

it('leaves the CAE and the goods answer alone when a form does not ask them', function () {
    app(FinanceSettings::class)->set(['cae' => '62010', 'sells_goods' => true]);

    $values = TaxSettingsFields::toSettings(['irs_coefficient' => '0.75', 'ss_rate' => '0.214', 'ss_relevant_income_share_percent' => 70]);

    expect($values)->not->toHaveKey('cae')->not->toHaveKey('sells_goods');
});
