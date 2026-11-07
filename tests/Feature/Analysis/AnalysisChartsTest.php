<?php

use App\Filament\Analysis\Widgets\ForecastInflowsByClientChart;
use App\Filament\Analysis\Widgets\ForecastInflowsChart;
use App\Filament\Analysis\Widgets\ForecastOutflowsChart;
use App\Filament\Analysis\Widgets\MarginsBreakdownChart;
use App\Filament\Analysis\Widgets\MarginsByClientChart;
use App\Filament\Analysis\Widgets\MarginsByLineChart;
use App\Filament\Analysis\Widgets\MarginsChart;
use App\Filament\Pages\CashForecast as CashForecastPage;
use App\Filament\Pages\Margins;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\Retainer;
use App\Support\CashForecast;
use App\Support\FinanceSettings;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-06'));
    actingAsAdmin();

    $printee = Project::factory()->create(['business_line' => 'Printee', 'quoted_on' => '2026-03-01', 'net_amount' => 1000]);
    Receivable::factory()->for($printee, 'billable')->create(['amount' => 1000, 'due_on' => '2026-12-10']);
    Receivable::factory()->for(Project::factory()->state(['quoted_on' => '2026-01-01']), 'billable')->create(['amount' => 300, 'due_on' => '2026-10-01']); // overdue
    Retainer::factory()->create(['starts_on' => '2026-12-01', 'due_day' => 1, 'net_amount' => 20]);
    Expense::factory()->unpaid()->create(['spent_on' => '2026-11-20', 'net_amount' => 40]);
});

it('splits the forecast inflows and outflows by where they come from', function () {
    $forecast = new CashForecast(app(FinanceSettings::class), 3);

    $in = $forecast->inflowBreakdown();
    $out = $forecast->outflowBreakdown();

    expect($in)->toBe(['A receber' => 1000.0, 'Em atraso' => 300.0, 'Avenças por gerar' => 40.0]) // Dec + Jan
        ->and(array_sum($in))->toBe($forecast->totals()['inflows'])
        ->and($out['Despesas por pagar'])->toBe(40.0)
        ->and(round(array_sum($out), 2))->toBe($forecast->totals()['outflows']);
});

it('shows forecast doughnuts for inflows and outflows', function () {
    Livewire::test(ForecastInflowsChart::class, ['months' => 3])
        ->assertSee('Entradas previstas')
        ->assertSee('A receber · '.Money::format(1000), escape: false);

    Livewire::test(ForecastOutflowsChart::class, ['months' => 3])
        ->assertSee('Saídas previstas')
        ->assertSee('Despesas por pagar · '.Money::format(40), escape: false);
});

it('shows margin charts by business line and by client', function () {
    Livewire::test(MarginsChart::class, ['year' => 2026])
        ->assertSee('Faturado')->assertSee('Custos diretos')->assertSee('Margem')->assertSee('Printee');

    Livewire::test(MarginsByLineChart::class, ['year' => 2026])
        ->assertSee('Faturado por linha de negócio')
        ->assertSee('Printee · '.Money::format(1000), escape: false);

    Livewire::test(MarginsByClientChart::class, ['year' => 2026])->assertSee('Faturado por cliente');
});

it('passes the current horizon and period to the widgets after they change', function () {
    $forecast = Livewire::test(CashForecastPage::class);
    $forecast->set('months', 6);

    expect($forecast->instance()->getWidgetData())->toMatchArray(['months' => 6]);

    $margins = Livewire::test(Margins::class)->set('year', 2025)->set('month', 3);

    expect($margins->instance()->getWidgetData())->toMatchArray(['year' => 2025, 'month' => 3]);
});

it('shows where the invoiced money goes: direct costs, overhead and result', function () {
    Expense::factory()->create(['spent_on' => '2026-05-01', 'net_amount' => 100]); // overhead

    Livewire::test(MarginsBreakdownChart::class, ['year' => 2026])
        ->assertSee('Para onde vai o faturado')
        ->assertSee('Custos de estrutura · '.Money::format(140), escape: false) // + the 40 € unpaid one from beforeEach
        ->assertSee('Resultado · ', escape: false);
});

it('splits the expected inflows by client', function () {
    $forecast = new CashForecast(app(FinanceSettings::class), 3);

    expect(array_sum($forecast->inflowsByClient()))->toBe($forecast->totals()['inflows'])
        ->and(count($forecast->inflowsByClient()))->toBeGreaterThan(1);
});

it('adds the by-client doughnut only for the 6-month horizon', function () {
    $page = Livewire::test(CashForecastPage::class);

    expect($page->instance()->sideWidgets())->not->toContain(ForecastInflowsByClientChart::class);

    $page->set('months', 6);

    expect($page->instance()->sideWidgets())->toContain(ForecastInflowsByClientChart::class);

    Livewire::test(ForecastInflowsByClientChart::class, ['months' => 6])
        ->assertSee('Entradas previstas por cliente');
});
