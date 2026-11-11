<?php

use App\Filament\Pages\FinanceSettingsPage;
use App\Filament\Support\TaxSettingsFields;
use App\Support\FinanceSettings;
use Livewire\Livewire;

pest()->group('fiscal', 'ui');

/*
 * The activity code and "I sell or transport goods" on the tax settings page: the second is what
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

it('leaves the CAE and the goods answer alone when a form does not ask them', function () {
    app(FinanceSettings::class)->set(['cae' => '62010', 'sells_goods' => true]);

    $values = TaxSettingsFields::toSettings(['irs_coefficient' => '0.75', 'ss_rate' => '0.214', 'ss_relevant_income_share_percent' => 70]);

    expect($values)->not->toHaveKey('cae')->not->toHaveKey('sells_goods');
});
