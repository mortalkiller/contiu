<?php

use App\Enums\CategoryType;
use App\Filament\Widgets\ExpensesByCategoryChart;
use App\Filament\Widgets\IncomeByCategoryChart;
use App\Models\Category;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\Retainer;
use App\Support\Money;
use App\Support\Period;
use App\Support\PeriodSummary;
use App\Support\SliceFolder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-11-06')));

it('sums what came in per income category, largest first', function () {
    $development = Category::factory()->income()->create(['name' => 'Desenvolvimento']);
    $maintenance = Category::factory()->income()->create(['name' => 'Manutenção']);

    $job = Project::factory()->create(['category_id' => $development->id]);
    Payment::factory()->for(Receivable::factory()->for($job, 'billable'))->create(['paid_on' => '2026-03-10', 'amount' => 900]);
    $retainer = Retainer::factory()->create(['category_id' => $maintenance->id]);
    Payment::factory()->for(Receivable::factory()->for($retainer, 'billable'))->create(['paid_on' => '2026-04-10', 'amount' => 20]);
    Payment::factory()->create(['paid_on' => '2026-05-10', 'amount' => 50]); // project without category
    Payment::factory()->for(Receivable::factory()->for($job, 'billable'))->create(['paid_on' => '2025-12-31', 'amount' => 999]);

    expect((new PeriodSummary(Period::of(2026)))->receivedByCategory())
        ->toBe(['Desenvolvimento' => 900.0, 'Sem categoria' => 50.0, 'Manutenção' => 20.0]);
});

it('keeps at most six slices, folding the smallest into "Outras"', function () {
    $values = ['A' => 70.0, 'B' => 60.0, 'C' => 50.0, 'D' => 40.0, 'E' => 30.0, 'F' => 20.0, 'G' => 10.0, 'H' => 5.0];

    expect(SliceFolder::fold($values))
        ->toBe(['A' => 70.0, 'B' => 60.0, 'C' => 50.0, 'D' => 40.0, 'E' => 30.0, 'Outras' => 35.0])
        ->and(SliceFolder::fold(['A' => 1.0, 'B' => 2.0]))->toBe(['A' => 1.0, 'B' => 2.0]);
});

it('draws both category charts as doughnuts with value and share in the legend', function () {
    actingAsAdmin();
    Expense::factory()->create(['spent_on' => '2026-03-01', 'net_amount' => 30, 'category_id' => Category::factory()->create(['type' => CategoryType::Expense, 'name' => 'Software'])->id]);
    Payment::factory()->create(['paid_on' => '2026-03-02', 'amount' => 100]);

    $income = Livewire::test(IncomeByCategoryChart::class, ['pageFilters' => ['year' => 2026]]);
    $expenses = Livewire::test(ExpensesByCategoryChart::class, ['pageFilters' => ['year' => 2026]]);

    $income->assertSee('doughnut', escape: false)
        ->assertSee('Receitas por categoria')
        ->assertSee('Sem categoria · '.Money::format(100).' (100%)', escape: false);
    $expenses->assertSee('doughnut', escape: false)
        ->assertSee('Despesas por categoria')
        ->assertSee('Software · '.Money::format(30).' (100%)', escape: false);
});
