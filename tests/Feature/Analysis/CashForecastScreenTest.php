<?php

use App\Filament\Analysis\Widgets\CashForecastTable;
use App\Filament\Analysis\Widgets\ForecastInflowsChart;
use App\Filament\Analysis\Widgets\ForecastOutflowsChart;
use App\Filament\Pages\CashForecast as CashForecastPage;
use App\Filament\Pages\FinanceSettingsPage;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\Retainer;
use App\Support\CashForecast;
use App\Support\FinanceSettings;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

describe('Forecast page and monthly table', function () {
    beforeEach(function () {
        $this->travelTo(Carbon::parse('2026-11-06'));
        actingAsAdmin();
    });

    it('warns on the forecast while the fiscal settings are incomplete', function () {
        Livewire::test(CashForecastPage::class)
            ->assertSee('Definições fiscais incompletas: valores aproximados')
            ->assertSee(FinanceSettingsPage::getUrl());

        app(FinanceSettings::class)->set(['activity_started_on' => '2024-01-01', 'activity_start_kind' => 'first']);

        Livewire::test(CashForecastPage::class)
            ->assertDontSee('Definições fiscais incompletas');
    })->group('ui');

    it('forecasts 3 months by default and switches to 6', function () {
        Livewire::test(CashForecastPage::class)
            ->assertSet('months', 3)
            ->assertSee('importa o extrato do banco')
            ->callAction('sixMonths')
            ->assertSet('months', 6);
    })->group('ui');

    it('lists the forecast months with a total row', function () {
        Receivable::factory()->create(['amount' => 40000, 'due_on' => '2026-12-10']);

        Livewire::test(CashForecastTable::class, ['months' => 3])
            ->assertSeeInOrder(['Novembro 2026', 'Dezembro 2026', Money::format(40000), 'Janeiro 2027', 'Total']);
    })->group('ui');
});

describe('Forecast charts', function () {
    beforeEach(function () {
        $this->travelTo(Carbon::parse('2026-11-06'));
        actingAsAdmin();

        $printee = Project::factory()->create(['business_line' => 'Printee', 'quoted_on' => '2026-03-01', 'net_amount' => 100000]);
        Receivable::factory()->for($printee, 'billable')->create(['amount' => 100000, 'due_on' => '2026-12-10']);
        Receivable::factory()->for(Project::factory()->state(['quoted_on' => '2026-01-01']), 'billable')->create(['amount' => 30000, 'due_on' => '2026-10-01']); // overdue
        Retainer::factory()->create(['starts_on' => '2026-12-01', 'due_day' => 1, 'net_amount' => 2000]);
        Expense::factory()->unpaid()->create(['spent_on' => '2026-11-20', 'net_amount' => 4000]);
    });

    it('splits the forecast inflows and outflows by where they come from', function () {
        $forecast = new CashForecast(app(FinanceSettings::class), 3);

        $in = $forecast->inflowBreakdown();
        $out = $forecast->outflowBreakdown();

        expect($in)->toBe(['A receber' => 100000, 'Em atraso' => 30000, 'Avenças por gerar' => 4000]) // Dec + Jan
            ->and(array_sum($in))->toBe($forecast->totals()['inflows'])
            ->and($out['Despesas por pagar'])->toBe(4000)
            ->and(array_sum($out))->toBe($forecast->totals()['outflows']);
    })->group('integration');

    it('shows forecast doughnuts for inflows and outflows', function () {
        Livewire::test(ForecastInflowsChart::class, ['months' => 3])
            ->assertSee('Entradas previstas')
            ->assertSee('A receber')->assertSee(Money::format(100000));

        Livewire::test(ForecastOutflowsChart::class, ['months' => 3])
            ->assertSee('Saídas previstas')
            ->assertSee('Despesas por pagar')->assertSee(Money::format(4000));
    })->group('ui');

    it('splits the expected inflows by client', function () {
        $forecast = new CashForecast(app(FinanceSettings::class), 3);

        expect(array_sum($forecast->inflowsByClient()))->toBe($forecast->totals()['inflows'])
            ->and(count($forecast->inflowsByClient()))->toBeGreaterThan(1);
    })->group('integration');

    it('shows where the money comes from and goes as bars, by client only over six months', function () {
        Livewire::test(CashForecastPage::class)
            ->assertSee('De onde vem e para onde vai')
            ->assertSeeHtml('contiu-proportion')
            ->assertDontSee('Entradas previstas por cliente')
            ->callAction('sixMonths')
            ->assertSee('Entradas previstas por cliente');
    })->group('ui');

    it('passes the selected horizon to the forecast widgets', function () {
        $forecast = Livewire::test(CashForecastPage::class);
        $forecast->set('months', 6);

        expect($forecast->instance()->getWidgetData())->toMatchArray(['months' => 6]);
    })->group('ui');
});
