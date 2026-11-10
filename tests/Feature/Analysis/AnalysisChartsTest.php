<?php

use App\Filament\Analysis\Widgets\ForecastInflowsByClientChart;
use App\Filament\Analysis\Widgets\ForecastInflowsChart;
use App\Filament\Analysis\Widgets\ForecastOutflowsChart;
use App\Filament\Analysis\Widgets\MarginsBreakdownChart;
use App\Filament\Analysis\Widgets\MarginsByClientChart;
use App\Filament\Analysis\Widgets\MarginsByClientTable;
use App\Filament\Analysis\Widgets\MarginsByLineChart;
use App\Filament\Analysis\Widgets\MarginsChart;
use App\Filament\Pages\CashForecast as CashForecastPage;
use App\Filament\Pages\Margins;
use App\Models\Client;
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
});

it('shows forecast doughnuts for inflows and outflows', function () {
    Livewire::test(ForecastInflowsChart::class, ['months' => 3])
        ->assertSee('Entradas previstas')
        ->assertSee('A receber · '.Money::format(100000), escape: false);

    Livewire::test(ForecastOutflowsChart::class, ['months' => 3])
        ->assertSee('Saídas previstas')
        ->assertSee('Despesas por pagar · '.Money::format(4000), escape: false);
});

it('shows margin charts by business line and by client', function () {
    Livewire::test(MarginsChart::class, ['year' => 2026])
        ->assertSee('Faturado')->assertSee('Custos diretos')->assertSee('Margem')->assertSee('Printee');

    Livewire::test(MarginsByLineChart::class, ['year' => 2026])
        ->assertSee('Faturado por linha de negócio')
        ->assertSee('Printee · '.Money::format(100000), escape: false);

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
    Expense::factory()->create(['spent_on' => '2026-05-01', 'net_amount' => 10000]); // overhead

    Livewire::test(MarginsBreakdownChart::class, ['year' => 2026])
        ->assertSee('Para onde vai o faturado')
        ->assertSee('Custos de estrutura · '.Money::format(14000), escape: false) // + the 40 € unpaid one from beforeEach
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

it('draws the margin percentage as a bar and warns when one client carries half the billing', function () {
    // The setup above bills 1 300,00 € to other clients; 3 900,00 € more to one makes them 75%.
    $big = Client::factory()->create(['name' => 'Estúdio Atlântico']);
    Receivable::factory()->for(Project::factory()->for($big)->create(['quoted_on' => '2026-10-01', 'net_amount' => 390000]), 'billable')->create(['amount' => 390000, 'due_on' => '2026-10-07']);

    Livewire::test(MarginsByClientTable::class, ['year' => 2026])
        ->loadTable()
        ->assertSeeHtml('contiu-percent-bar');

    Livewire::test(Margins::class, ['year' => 2026])
        ->assertSee('Estúdio Atlântico representa 75% do faturado');
});

it('says nothing about dependence when the billing is spread out', function () {
    // Two more clients at 1 000,00 € each leave the biggest one at 30%.
    foreach (range(1, 2) as $ignored) {
        Receivable::factory()->for(Project::factory()->create(['quoted_on' => '2026-10-01', 'net_amount' => 100000]), 'billable')->create(['amount' => 100000, 'due_on' => '2026-10-07']);
    }

    Livewire::test(Margins::class, ['year' => 2026])->assertDontSee('representa');
});
