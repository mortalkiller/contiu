<?php

use App\Filament\Analysis\Widgets\CashForecastTable;
use App\Filament\Analysis\Widgets\MarginsByClientTable;
use App\Filament\Analysis\Widgets\MarginsByLineTable;
use App\Filament\Analysis\Widgets\TaxQuartersTable;
use App\Filament\Pages\CashForecast;
use App\Filament\Pages\FinanceSettingsPage;
use App\Filament\Pages\Margins;
use App\Filament\Pages\TaxEstimate;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Support\FinanceSettings;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-06'));
    actingAsAdmin();
});

// Definições fiscais ------------------------------------------------------------------------

it('shows the fiscal settings with their defaults and saves changes', function () {
    Livewire::test(FinanceSettingsPage::class)
        ->assertSee('confirma os valores com o teu contabilista')
        ->assertSee('Limite anual de isenção de IVA (art. 53.º)')
        ->assertSchemaStateSet(['irs_exempt' => true, 'vat_exempt' => true, 'ss_rate_percent' => 21.4, 'vat_exemption_threshold' => 15000.0], 'form')
        ->fillForm(['irs_exempt' => false, 'ss_rate_percent' => 21.4, 'current_bank_balance' => 2500, 'current_bank_balance_on' => '2026-11-05'])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(FinanceSettings::class);

    expect($settings->irsExempt())->toBeFalse()
        ->and($settings->ssRate())->toBe(0.214)
        ->and($settings->currentBankBalance())->toBe(2500.0);
});

// Previsão de tesouraria --------------------------------------------------------------------

it('forecasts 3 months by default and switches to 6', function () {
    Livewire::test(CashForecast::class)
        ->assertSet('months', 3)
        ->assertSee('define o saldo atual')
        ->callAction('sixMonths')
        ->assertSet('months', 6);
});

it('lists the forecast months with a total row', function () {
    Receivable::factory()->create(['amount' => 400, 'due_on' => '2026-12-10']);

    Livewire::test(CashForecastTable::class, ['months' => 3])
        ->assertSeeInOrder(['Novembro 2026', 'Dezembro 2026', Money::format(400), 'Janeiro 2027', 'Total']);
});

// Margens -----------------------------------------------------------------------------------

it('shows margins by business line and by client for the chosen year', function () {
    $client = Client::factory()->create(['name' => 'Gonçalo']);
    $job = Project::factory()->for($client)->create(['business_line' => 'Printee', 'quoted_on' => '2026-03-01', 'net_amount' => 1000]);
    Payment::factory()->for(Receivable::factory()->for($job, 'billable')->create(['amount' => 1000]))->create(['paid_on' => '2026-04-01', 'amount' => 1000]);

    Livewire::test(Margins::class)->assertSet('year', 2026)->assertSee('Margens');

    Livewire::test(MarginsByLineTable::class, ['year' => 2026, 'month' => null])
        ->assertSeeInOrder(['Printee', Money::format(1000)]);

    Livewire::test(MarginsByClientTable::class, ['year' => 2026, 'month' => null])
        ->assertSee('Gonçalo');
});

// Impostos ----------------------------------------------------------------------------------

it('estimates taxes for the year with the exemptions from the settings', function () {
    Livewire::test(TaxEstimate::class)
        ->assertSet('year', 2026)
        ->assertSee('Isento (definição)')
        ->assertSee('Limite de isenção de IVA');

    Livewire::test(TaxQuartersTable::class, ['year' => 2026])
        ->assertSeeInOrder(['Jan – Mar', 'Abr – Jun', 'Jul – Set', 'Out – Dez']);
});
