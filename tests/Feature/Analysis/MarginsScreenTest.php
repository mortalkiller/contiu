<?php

use App\Filament\Analysis\Widgets\MarginsBreakdownChart;
use App\Filament\Analysis\Widgets\MarginsByClientChart;
use App\Filament\Analysis\Widgets\MarginsByClientTable;
use App\Filament\Analysis\Widgets\MarginsByLineChart;
use App\Filament\Analysis\Widgets\MarginsByLineTable;
use App\Filament\Analysis\Widgets\MarginsChart;
use App\Filament\Pages\Margins;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\Retainer;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

pest()->group('ui');

describe('Margins page and tables', function () {
    beforeEach(function () {
        $this->travelTo(Carbon::parse('2026-11-06'));
        actingAsAdmin();
    });

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
});

describe('Charts and client concentration', function () {
    beforeEach(function () {
        $this->travelTo(Carbon::parse('2026-11-06'));
        actingAsAdmin();

        $printee = Project::factory()->create(['business_line' => 'Printee', 'quoted_on' => '2026-03-01', 'net_amount' => 100000]);
        Receivable::factory()->for($printee, 'billable')->create(['amount' => 100000, 'due_on' => '2026-12-10']);
        Receivable::factory()->for(Project::factory()->state(['quoted_on' => '2026-01-01']), 'billable')->create(['amount' => 30000, 'due_on' => '2026-10-01']); // overdue
        Retainer::factory()->create(['starts_on' => '2026-12-01', 'due_day' => 1, 'net_amount' => 2000]);
        Expense::factory()->unpaid()->create(['spent_on' => '2026-11-20', 'net_amount' => 4000]);
    });

    it('shows margin charts by business line and by client', function () {
        Livewire::test(MarginsChart::class, ['year' => 2026])
            ->assertSee('Faturado')->assertSee('Custos diretos')->assertSee('Margem')->assertSee('Printee');

        Livewire::test(MarginsByLineChart::class, ['year' => 2026])
            ->assertSee('Faturado por linha de negócio')
            ->assertSee('Printee')->assertSee(Money::format(100000));

        Livewire::test(MarginsByClientChart::class, ['year' => 2026])->assertSee('Faturado por cliente');
    });

    it('shows where the invoiced money goes: direct costs, overhead and result', function () {
        Expense::factory()->create(['spent_on' => '2026-05-01', 'net_amount' => 10000]); // overhead

        Livewire::test(MarginsBreakdownChart::class, ['year' => 2026])
            ->assertSee('Para onde vai o faturado')
            ->assertSee('Custos de estrutura')->assertSee(Money::format(14000)) // + the 40 € unpaid one from beforeEach
            ->assertSee('Resultado');
    });

    it('draws the margin percentage as a bar and warns when one client carries half the billing', function () {
        // The setup above bills 1 300,00 € to other clients; 3 900,00 € more to one makes them 75%.
        $big = Client::factory()->create(['name' => 'Estúdio Atlântico']);
        Receivable::factory()->for(Project::factory()->for($big)->create(['quoted_on' => '2026-10-01', 'net_amount' => 390000]), 'billable')->create(['amount' => 390000, 'due_on' => '2026-10-07']);

        Livewire::test(MarginsByClientTable::class, ['year' => 2026])
            ->loadTable()
            ->assertSeeHtml('fap-progress-with-value');

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

    it('passes the selected period to the margin widgets', function () {
        $margins = Livewire::test(Margins::class)->set('year', 2025)->set('month', 3);

        expect($margins->instance()->getWidgetData())->toMatchArray(['year' => 2025, 'month' => 3]);
    });
    it('picks the period in the header and splits the invoiced money as one bar', function () {
        Livewire::test(Margins::class, ['year' => 2026])
            ->assertSee('O que faturaste, menos os custos diretos de cada trabalho e avença.')
            ->assertSeeHtml('contiu-proportion')
            ->assertSee('Para onde vai o faturado')
            ->callAction('month_3')
            ->assertSet('month', 3)
            ->callAction('month_0')
            ->assertSet('month', null);
    });
});
