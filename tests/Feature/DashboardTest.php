<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\MonthClose;
use App\Filament\Widgets\CashFlowChart;
use App\Filament\Widgets\ExpensesByCategoryChart;
use App\Filament\Widgets\FinanceStatsOverview;
use App\Filament\Widgets\MonthComparisonChart;
use App\Filament\Widgets\MonthlyCashFlowTable;
use App\Filament\Widgets\PeriodSummaryTable;
use App\Filament\Widgets\UpcomingReceivablesTable;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Support\Money;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-06'));
    actingAsAdmin();

    $job = Project::factory()->create(['quoted_on' => '2026-03-04', 'net_amount' => 1000]);
    $due = Receivable::factory()->for($job, 'billable')->create(['amount' => 1000, 'due_on' => '2026-12-15']);
    Payment::factory()->for($due)->create(['paid_on' => '2026-03-31', 'amount' => 300]);

    $old = Project::factory()->create(['quoted_on' => '2025-02-01', 'net_amount' => 100]);
    $oldDue = Receivable::factory()->for($old, 'billable')->create(['amount' => 100, 'due_on' => '2025-02-10']);
    Payment::factory()->for($oldDue)->create(['paid_on' => '2025-02-10', 'amount' => 100]);
});

it('filters the dashboard by year and, optionally, month', function () {
    Livewire::test(Dashboard::class)
        ->assertSchemaComponentExists('year', 'filtersForm')
        ->assertSchemaComponentExists('month', 'filtersForm');
});

it('shows each figure with its change against the previous period', function () {
    Livewire::test(FinanceStatsOverview::class, ['pageFilters' => ['year' => 2026]])
        ->assertSee('Recebido em 2026')
        ->assertSee(Money::format(300.00))
        ->assertSee('+200% vs 2025');

    Livewire::test(FinanceStatsOverview::class, ['pageFilters' => ['year' => 2026, 'month' => 3]])
        ->assertSee('Recebido em Março 2026')
        ->assertSee('vs Fevereiro 2026');
});

it('shows the Excel period summary next to the previous period', function () {
    Livewire::test(PeriodSummaryTable::class, ['pageFilters' => ['year' => 2026]])
        ->assertSeeInOrder(['Total contratado', Money::format(1000.00), Money::format(100.00)])
        ->assertSeeInOrder(['Total por receber', Money::format(700.00)])
        ->assertSee('Resultado previsto');
});

it('shows the monthly cash flow table with a total row', function () {
    Livewire::test(MonthlyCashFlowTable::class, ['pageFilters' => ['year' => 2026]])
        ->assertSeeInOrder(['Jan', 'Fev', 'Mar', Money::format(300.00), 'Dez', 'Total', Money::format(300.00)]);

    Livewire::test(MonthlyCashFlowTable::class, ['pageFilters' => ['year' => 2026, 'month' => 3]])
        ->assertSee('Dia')
        ->assertSeeInOrder(['31', Money::format(300.00)]);
});

it('renders the charts and the receivables list for a month', function () {
    $filters = ['pageFilters' => ['year' => 2026, 'month' => 3]];

    Livewire::test(CashFlowChart::class, $filters)->assertSee('Março 2026');
    Livewire::test(ExpensesByCategoryChart::class, $filters)->assertSee('Março 2026');
    Livewire::test(UpcomingReceivablesTable::class, $filters)->assertSee(Money::format(700.00));
});

it('says plainly when the previous period had no value', function () {
    Expense::factory()->create(['spent_on' => '2026-05-05', 'net_amount' => 10]); // none in 2025

    Livewire::test(FinanceStatsOverview::class, ['pageFilters' => ['year' => 2026]])
        ->assertSee('sem valor em 2025')
        ->assertDontSee('sem valor no período anterior vs');
});

it('swaps the monthly cash flow chart for a comparison with the previous month when a month is chosen', function () {
    $dashboard = Livewire::test(Dashboard::class)->set('filters', ['year' => 2026, 'month' => null]);

    expect($dashboard->instance()->getWidgets())
        ->toContain(CashFlowChart::class)
        ->not->toContain(MonthComparisonChart::class);

    $dashboard->set('filters', ['year' => 2026, 'month' => 3]);

    expect($dashboard->instance()->getWidgets())
        ->not->toContain(CashFlowChart::class)
        ->toContain(MonthComparisonChart::class);
});

it('compares the chosen month with the month before', function () {
    Livewire::test(MonthComparisonChart::class, ['pageFilters' => ['year' => 2026, 'month' => 3]])
        ->assertSee('Março 2026 vs Fevereiro 2026')
        ->assertSee('Recebido')
        ->assertSee('Despesas pagas')
        ->assertSee('Resultado');
});

it('links to the month close of the chosen month, or the current one, from the filters', function () {
    Livewire::test(Dashboard::class)
        ->set('filters', ['year' => 2026, 'month' => 3])
        ->assertSee('Fecho do mês')
        ->assertSee(MonthClose::getUrl(['month' => '2026-03']), escape: false)
        ->set('filters', ['year' => 2026, 'month' => null])
        ->assertSee(MonthClose::getUrl(['month' => '2026-11']), escape: false);
});

it('opens on the whole current year by default', function () {
    Livewire::test(Dashboard::class)
        ->assertSet('filters.year', 2026)
        ->assertSet('filters.month', null);
});

it('opens on the current year again even after another period was chosen', function () {
    Livewire::test(Dashboard::class)->set('filters', ['year' => 2025, 'month' => 3]);

    Livewire::test(Dashboard::class)
        ->assertSet('filters.year', 2026)
        ->assertSet('filters.month', null);
});

it('uses standard Filament table widgets for the summary and the monthly cash flow', function () {
    expect(is_subclass_of(PeriodSummaryTable::class, TableWidget::class))->toBeTrue()
        ->and(is_subclass_of(MonthlyCashFlowTable::class, TableWidget::class))->toBeTrue();

    Livewire::test(PeriodSummaryTable::class, ['pageFilters' => ['year' => 2026]])
        ->assertSee('Resumo do período')
        ->assertDontSee('Comparado com');
});

it('greets the user by first name according to the time of day', function (string $time, string $greeting) {
    $this->travelTo(Carbon::parse('2026-11-06 '.$time, 'Europe/Lisbon'));
    auth()->user()->update(['name' => 'Pedro Monteiro']);

    Livewire::test(Dashboard::class)->assertSee($greeting.', Pedro');
})->with([
    'manhã' => ['09:15', 'Bom dia'],
    'meio-dia' => ['12:00', 'Boa tarde'],
    'tarde' => ['17:40', 'Boa tarde'],
    'noite' => ['20:00', 'Boa noite'],
    'madrugada' => ['02:30', 'Boa noite'],
    'cedo' => ['06:00', 'Bom dia'],
]);

it('keeps "Painel" as the browser tab title', function () {
    expect(Livewire::test(Dashboard::class)->instance()->getTitle())->toBe('Painel');
});
