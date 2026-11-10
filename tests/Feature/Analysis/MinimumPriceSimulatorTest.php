<?php

use App\Filament\Pages\MinimumPriceSimulator;
use App\Models\Account;
use App\Support\CurrentAccount;
use App\Support\Money;
use Filament\Facades\Filament;
use Livewire\Livewire;

it('renders the minimum turnover for each period in a read-only account', function () {
    $this->account->update(['trial_ends_at' => now()->subDay()]);
    $owner = ownerOf($this->account);
    asAWebRequest();

    $this->actingAs($owner)
        ->get(MinimumPriceSimulator::getUrl(panel: 'admin', tenant: $this->account))
        ->assertOk()
        ->assertSee('Simulador de preço mínimo');

    Filament::setTenant($this->account);
    expect(CurrentAccount::readOnly())->toBeTrue();

    Livewire::test(MinimumPriceSimulator::class)
        ->fillForm([
            'incomeGoal' => '24 000,00',
            'costs' => '6000,00',
            'billableMonths' => '10',
            'hoursPerMonth' => '120',
            'reservePercent' => '20',
        ])
        ->call('calculate')
        ->assertHasNoFormErrors()
        ->assertSet('result', ['annual' => 3750000, 'monthly' => 375000, 'hourly' => 3125])
        ->assertSee(Money::format(3750000))
        ->assertSee(Money::format(375000))
        ->assertSee(Money::format(3125));

    Livewire::test(MinimumPriceSimulator::class)->assertSet('result', null);
});

it('rounds each minimum up independently from the exact calculation', function () {
    actingAsAdmin();

    Livewire::test(MinimumPriceSimulator::class)
        ->fillForm([
            'incomeGoal' => '0',
            'costs' => '0,01',
            'billableMonths' => '3',
            'hoursPerMonth' => '0,5',
            'reservePercent' => '1',
        ])
        ->call('calculate')
        ->assertHasNoFormErrors()
        ->assertSet('result', ['annual' => 2, 'monthly' => 1, 'hourly' => 1]);
});

it('displays the exact cents of a large hourly minimum', function () {
    actingAsAdmin();

    Livewire::test(MinimumPriceSimulator::class)
        ->fillForm([
            'incomeGoal' => '999 999 999,99',
            'costs' => '999 999 999,98',
            'billableMonths' => '1',
            'hoursPerMonth' => '0,03',
            'reservePercent' => '99,99',
        ])
        ->call('calculate')
        ->assertHasNoFormErrors()
        ->assertSee('666 666 666 656 666,67 €');
});

it('rejects invalid inputs without keeping an earlier result', function (string $field, string $value) {
    actingAsAdmin();

    Livewire::test(MinimumPriceSimulator::class)
        ->fillForm([
            'incomeGoal' => '1',
            'costs' => '0',
            'billableMonths' => '12',
            'hoursPerMonth' => '1',
            'reservePercent' => '0',
        ])
        ->call('calculate')
        ->assertHasNoFormErrors()
        ->assertSet('result.annual', 100)
        ->fillForm([$field => $value])
        ->call('calculate')
        ->assertHasFormErrors([$field])
        ->assertSet('result', null);
})->with([
    'zero billable months' => ['billableMonths', '0'],
    'more than a year' => ['billableMonths', '13'],
    'zero billable hours' => ['hoursPerMonth', '0,00'],
    'reserve consumes all turnover' => ['reservePercent', '100'],
    'negative annual costs' => ['costs', '-0,01'],
    'malformed annual goal' => ['incomeGoal', '100abc'],
]);

it('returns 404 for an account the owner cannot access', function () {
    $owner = ownerOf($this->account);
    $otherAccount = Account::factory()->create();
    asAWebRequest();

    $this->actingAs($owner)
        ->get(MinimumPriceSimulator::getUrl(panel: 'admin', tenant: $otherAccount))
        ->assertNotFound();
});
