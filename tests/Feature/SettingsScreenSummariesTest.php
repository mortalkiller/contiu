<?php

use App\Filament\Pages\AccountantAccessSettings;
use App\Filament\Pages\AccountSubscription;
use App\Filament\Pages\FinanceSettingsPage;
use App\Filament\Pages\MinimumPriceSimulator;
use App\Models\FiscalDocument;
use App\Models\User;
use App\Support\AccountantAccess;
use App\Support\Money;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-10 12:00'));
    actingAsAdmin();
});

it('explains the simulated minimum and compares it with this year\'s billing', function () {
    FiscalDocument::factory()->create(['net_amount' => 375000, 'document_date' => '2026-06-01']);

    $page = Livewire::test(MinimumPriceSimulator::class)
        ->assertDontSee('Como se chega aqui')
        ->fillForm(['incomeGoal' => '24000', 'costs' => '6000', 'billableMonths' => '10', 'hoursPerMonth' => '120', 'reservePercent' => '20'])
        ->call('calculate')
        ->assertSee('Em 10 meses')
        ->assertSee('Em 120 horas por')
        ->assertSee('Como se chega aqui')
        ->assertSee('Comparado com o que cobras hoje');

    expect($page->instance()->comparison())->toBe([
        'year' => 2026,
        'billed' => Money::format(375000),
        'percent' => 10,
        'pace' => $page->instance()->formatMinimum(1687500),
        'reached' => false,
    ])->and((string) $page->instance()->breakdownBar())
        ->toContain('Rendimento', Money::format(2400000), 'Reserva', Money::format(750000), 'Custos', Money::format(600000));

    $page->set('data.costs', '7000')->assertDontSee('Como se chega aqui');
});

it('leaves the comparison out when nothing was billed in euros this year', function () {
    Livewire::test(MinimumPriceSimulator::class)
        ->fillForm(['incomeGoal' => '24000', 'costs' => '6000', 'billableMonths' => '10', 'hoursPerMonth' => '120', 'reservePercent' => '20'])
        ->call('calculate')
        ->assertSee('Como se chega aqui')
        ->assertDontSee('Comparado com o que cobras hoje');
});

it('says the fiscal profile is unconfirmed and that saving recalculates the estimates', function () {
    Livewire::test(FinanceSettingsPage::class)
        ->assertSee('Por confirmar. A estimativa usa as definições abaixo até confirmares uma versão.')
        ->assertSee('Mudar estas definições recalcula a estimativa de impostos e a previsão de tesouraria.');
});

it('shows how much of the free trial is used', function () {
    $account = Filament::getTenant();
    $account->forceFill(['created_at' => '2026-10-30 12:00', 'trial_ends_at' => '2026-11-13 12:00', 'billing_exempt' => false])->save();

    $page = Livewire::test(AccountSubscription::class);

    expect($page->instance()->trialProgress())->toBe(['percent' => 79, 'label' => 'Faltam 3 dias de experiência · 11 de 14 usados']);
    $page->assertSee('Faltam 3 dias de experiência');

    $account->forceFill(['trial_ends_at' => '2026-11-01 12:00'])->save();

    expect(Livewire::test(AccountSubscription::class)->instance()->trialProgress())->toBeNull();
});

it('shows since when each accountant has access', function () {
    $accountant = User::factory()->create();
    Filament::getTenant()->users()->attach($accountant, ['role' => AccountantAccess::ROLE]);

    Livewire::test(AccountantAccessSettings::class)
        ->assertTableColumnFormattedStateSet('access_since', '10/11/2026', $accountant);
});
