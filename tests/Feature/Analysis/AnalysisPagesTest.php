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
use App\Support\TaxEstimator;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-06'));
    actingAsAdmin();
});

// Definições fiscais ------------------------------------------------------------------------

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
});

// Previsão de tesouraria --------------------------------------------------------------------

it('warns on the forecast while the fiscal settings are incomplete', function () {
    Livewire::test(CashForecast::class)
        ->assertSee('Definições fiscais incompletas: valores aproximados')
        ->assertSee(FinanceSettingsPage::getUrl());

    app(FinanceSettings::class)->set(['activity_started_on' => '2024-01-01', 'activity_start_kind' => 'first']);

    Livewire::test(CashForecast::class)
        ->assertDontSee('Definições fiscais incompletas');
});

it('forecasts 3 months by default and switches to 6', function () {
    Livewire::test(CashForecast::class)
        ->assertSet('months', 3)
        ->assertSee('importa o extrato do banco')
        ->callAction('sixMonths')
        ->assertSet('months', 6);
});

it('lists the forecast months with a total row', function () {
    Receivable::factory()->create(['amount' => 40000, 'due_on' => '2026-12-10']);

    Livewire::test(CashForecastTable::class, ['months' => 3])
        ->assertSeeInOrder(['Novembro 2026', 'Dezembro 2026', Money::format(40000), 'Janeiro 2027', 'Total']);
});

// Margens -----------------------------------------------------------------------------------

it('shows margins by business line and by client for the chosen year', function () {
    $client = Client::factory()->create(['name' => 'Gonçalo']);
    $job = Project::factory()->for($client)->create(['business_line' => 'Printee', 'quoted_on' => '2026-03-01', 'net_amount' => 100000]);
    Payment::factory()->for(Receivable::factory()->for($job, 'billable')->create(['amount' => 100000]))->create(['paid_on' => '2026-04-01', 'amount' => 100000]);

    Livewire::test(Margins::class)->assertSet('year', 2026)->assertSee('Margens');

    Livewire::test(MarginsByLineTable::class, ['year' => 2026, 'month' => null])
        ->assertSeeInOrder(['Printee', Money::format(100000)]);

    Livewire::test(MarginsByClientTable::class, ['year' => 2026, 'month' => null])
        ->assertSee('Gonçalo');
});

// Impostos ----------------------------------------------------------------------------------

it('estimates taxes for the year with the exemptions from the settings', function () {
    app(FinanceSettings::class)->set(['vat_exempt' => true]);

    Livewire::test(TaxEstimate::class)
        ->assertSet('year', 2026)
        ->assertDontSee('Isento (definição)')
        ->assertDontSee('coeficiente de 75%')
        ->assertSee('Limite de isenção de IVA');

    Livewire::test(TaxQuartersTable::class, ['year' => 2026])
        ->assertSeeInOrder(['Jan – Mar', 'Abr – Jun', 'Jul – Set', 'Out – Dez']);
});

it('notes the first-enrolment months and the minimum contribution per quarter', function () {
    // A first start in August 2025: contributions are owed from August 2026 (an estimate).
    app(FinanceSettings::class)->set(['activity_started_on' => '2025-08-07', 'activity_start_kind' => 'first']);

    Livewire::test(TaxQuartersTable::class, ['year' => 2026])
        ->assertSeeInOrder([
            'Jan – Mar', 'Isento (1.º enquadramento)',
            'Abr – Jun', '1 mês isento (1.º enquadramento) · Contribuição mínima',
            'Jul – Set', 'Contribuição mínima',
        ]);

    // The first contribution is August's, paid in September.
    Livewire::test(TaxEstimate::class)
        ->assertSee('Isento (1.º enquadramento) até julho de 2026 (estimativa). A primeira contribuição, de agosto, paga-se em setembro.');
});

it('says in which months each quarter is paid', function () {
    app(FinanceSettings::class)->set(['activity_started_on' => '2024-01-01', 'activity_start_kind' => 'first']);

    Livewire::test(TaxQuartersTable::class, ['year' => 2026])
        ->assertSee('a de cada mês paga-se entre 10 e 20 do mês seguinte')
        ->assertSeeInOrder(['Jan – Mar', 'Mai – Jul 2026', 'Abr – Jun', 'Ago – Out 2026', 'Jul – Set', 'Nov 2026 – Jan 2027', 'Out – Dez', 'Fev – Abr 2027']);
});

it('notes a recognised contribution exemption', function () {
    app(FinanceSettings::class)->set(['activity_started_on' => '2024-01-01', 'activity_start_kind' => 'first', 'ss_contribution_exempt' => true]);

    Livewire::test(TaxQuartersTable::class, ['year' => 2026])
        ->assertSee('Isento (art. 157.º)')
        ->assertDontSee('Contribuição mínima');

    Livewire::test(TaxEstimate::class)
        ->assertSee('Isento (art. 157.º).');
});

it('shows each step of the IRS estimate', function () {
    app(FinanceSettings::class)->set([
        'activity_started_on' => '2025-08-07', 'activity_start_kind' => 'first',
        'irs_jovem' => true, 'irs_jovem_year' => ['year' => 2026, 'number' => 2],
    ]);
    Payment::factory()->create(['paid_on' => '2026-03-01', 'amount' => 2000000]);

    Livewire::test(TaxEstimate::class)
        ->assertSeeInOrder([
            'Rendimento tributável',
            'Rendimento bruto', Money::format(2000000),
            'Isento pelo IRS Jovem', Money::format(1500000),
            '0,5625', 'redução de 25 % — 2.º ano',
        ])
        ->assertSee(Money::format(281250))
        ->assertSee('sem informação vinculativa da AT')
        ->assertDontSee('Isento (definição)');
});

it('says why the early-years IRS reduction does not apply', function (array $settings, string $reason) {
    app(FinanceSettings::class)->set(['activity_started_on' => '2025-08-07', ...$settings]);

    Livewire::test(TaxEstimate::class)
        ->assertSee('Coeficiente 0,75 (sem redução: '.$reason.')');
})->with([
    'salary this year' => [['activity_start_kind' => 'first', 'dependent_income_years' => [2026]], 'ano com salário ou pensão'],
    'recent restart' => [['activity_start_kind' => 'restart', 'previous_activity_ended_on' => '2023-01-31'], 'reinício a menos de 5 anos da cessação'],
]);

it('gives no reduction reason once the early years are over', function () {
    app(FinanceSettings::class)->set(['activity_started_on' => '2020-08-07', 'activity_start_kind' => 'first', 'dependent_income_years' => [2026]]);

    Livewire::test(TaxEstimate::class)
        ->assertSee('Coeficiente 0,75')
        ->assertDontSee('sem redução');
});

it('warns on the estimate while the fiscal settings are incomplete', function () {
    Livewire::test(TaxEstimate::class)
        ->assertSee('Definições fiscais incompletas: valores aproximados');

    app(FinanceSettings::class)->set(['activity_started_on' => '2024-01-01', 'activity_start_kind' => 'first']);

    Livewire::test(TaxEstimate::class)
        ->assertDontSee('Definições fiscais incompletas');
});

it('leaves the monthly contribution blank in a quarter without charged months', function () {
    app(FinanceSettings::class)->set(['activity_started_on' => '2024-01-01', 'activity_start_kind' => 'first', 'ss_contribution_exempt' => true]);

    Livewire::test(TaxQuartersTable::class, ['year' => 2026])
        ->assertSee('Isento (art. 157.º)')
        ->assertDontSee(Money::format(TaxEstimator::SS_MINIMUM_CONTRIBUTION));
});
