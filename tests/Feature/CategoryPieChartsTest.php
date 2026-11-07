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
    Payment::factory()->for(Receivable::factory()->for($job, 'billable'))->create(['paid_on' => '2026-03-10', 'amount' => 90000]);
    $retainer = Retainer::factory()->create(['category_id' => $maintenance->id]);
    Payment::factory()->for(Receivable::factory()->for($retainer, 'billable'))->create(['paid_on' => '2026-04-10', 'amount' => 2000]);
    Payment::factory()->create(['paid_on' => '2026-05-10', 'amount' => 5000]); // project without category
    Payment::factory()->for(Receivable::factory()->for($job, 'billable'))->create(['paid_on' => '2025-12-31', 'amount' => 99900]);

    expect((new PeriodSummary(Period::of(2026)))->receivedByCategory())
        ->toBe(['Desenvolvimento' => 90000, 'Sem categoria' => 5000, 'Manutenção' => 2000]);
});

it('keeps at most six slices, folding the smallest into "Outras"', function () {
    $values = ['A' => 7000, 'B' => 6000, 'C' => 5000, 'D' => 4000, 'E' => 3000, 'F' => 2000, 'G' => 1000, 'H' => 500];

    expect(SliceFolder::fold($values))
        ->toBe(['A' => 7000, 'B' => 6000, 'C' => 5000, 'D' => 4000, 'E' => 3000, 'Outras' => 3500])
        ->and(SliceFolder::fold(['A' => 100, 'B' => 200]))->toBe(['A' => 100, 'B' => 200]);
});

it('draws both category charts as doughnuts with value and share in the legend', function () {
    actingAsAdmin();
    Expense::factory()->create(['spent_on' => '2026-03-01', 'net_amount' => 3000, 'category_id' => Category::factory()->create(['type' => CategoryType::Expense, 'name' => 'Software'])->id]);
    Payment::factory()->create(['paid_on' => '2026-03-02', 'amount' => 10000]);

    $income = Livewire::test(IncomeByCategoryChart::class, ['pageFilters' => ['year' => 2026]]);
    $expenses = Livewire::test(ExpensesByCategoryChart::class, ['pageFilters' => ['year' => 2026]]);

    $income->assertSee('doughnut', escape: false)
        ->assertSee('Receitas por categoria')
        ->assertSee('Sem categoria · '.Money::format(10000).' (100%)', escape: false);
    $expenses->assertSee('doughnut', escape: false)
        ->assertSee('Despesas por categoria')
        ->assertSee('Software · '.Money::format(3000).' (100%)', escape: false);
});
