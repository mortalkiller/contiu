<?php

use App\Enums\ProjectStatus;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\Retainer;
use App\Support\Change;
use App\Support\Period;
use App\Support\PeriodSummary;
use Illuminate\Support\Carbon;

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-11-06')));

// Período -----------------------------------------------------------------------------------

it('describes a whole year and its previous year', function () {
    $period = Period::of(2026);

    expect($period->start->toDateString())->toBe('2026-01-01')
        ->and($period->end->toDateString())->toBe('2026-12-31')
        ->and($period->label())->toBe('2026')
        ->and($period->previous()->label())->toBe('2025');
});

it('describes a month and the month before it, across years', function () {
    $period = Period::of(2026, 1);

    expect($period->start->toDateString())->toBe('2026-01-01')
        ->and($period->end->toDateString())->toBe('2026-01-31')
        ->and($period->label())->toBe('Janeiro 2026')
        ->and($period->previous()->label())->toBe('Dezembro 2025');
});

// Variação ----------------------------------------------------------------------------------

it('computes the change against the previous period', function () {
    expect(Change::between(150, 100)->percent)->toBe(50.0)
        ->and(Change::between(50, 100)->percent)->toBe(-50.0)
        ->and(Change::between(0, 0)->percent)->toBe(0.0)
        ->and(Change::between(80, 0)->percent)->toBeNull()
        ->and(Change::between(80, 0)->label())->toBe('sem valor no período anterior')
        ->and(Change::between(150, 100)->label())->toBe('+50%')
        ->and(Change::between(-20, 100)->label())->toBe('−120%');
});

// Resumo do período -------------------------------------------------------------------------

function seedPeriodData(): void
{
    $project = Project::factory()->create(['quoted_on' => '2026-03-04', 'net_amount' => 1000, 'vat_rate' => 0]);
    $instalment = Receivable::factory()->for($project, 'billable')->create(['amount' => 1000, 'due_on' => '2026-08-15']);
    Payment::factory()->for($instalment)->create(['paid_on' => '2026-03-31', 'amount' => 400]);

    $cancelled = Project::factory()->create(['quoted_on' => '2026-05-01', 'net_amount' => 999, 'status' => ProjectStatus::Cancelled]);
    Receivable::factory()->for($cancelled, 'billable')->create(['amount' => 999, 'due_on' => '2026-06-01']);

    $nextYearJob = Project::factory()->create(['quoted_on' => '2026-11-01', 'net_amount' => 500]);
    Receivable::factory()->for($nextYearJob, 'billable')->create(['amount' => 500, 'due_on' => '2027-01-31']);

    $retainer = Retainer::factory()->create();
    $march = Receivable::factory()->for($retainer, 'billable')->create(['amount' => 20, 'due_on' => '2026-03-01']);
    Payment::factory()->for($march)->create(['paid_on' => '2026-03-02', 'amount' => 20]);
    Receivable::factory()->for($retainer, 'billable')->create(['amount' => 20, 'due_on' => '2026-11-01']);

    Expense::factory()->create(['spent_on' => '2026-03-10', 'net_amount' => 100, 'vat_rate' => 0.23]);
    Expense::factory()->unpaid()->create(['spent_on' => '2026-11-02', 'net_amount' => 50]);
    $lastYear = Project::factory()->create(['quoted_on' => '2025-03-01', 'net_amount' => 200]);
    $lastYearDue = Receivable::factory()->for($lastYear, 'billable')->create(['amount' => 200, 'due_on' => '2025-03-05']);
    Payment::factory()->for($lastYearDue)->create(['paid_on' => '2025-03-05', 'amount' => 200]);
}

it('sums the year like the Excel summary', function () {
    seedPeriodData();

    $summary = new PeriodSummary(Period::of(2026));

    expect($summary->contracted())->toBe(1540.0)       // 1000 + 500 (quoted in 2026) + 2 × 20, cancelled excluded
        ->and($summary->received())->toBe(420.0)
        ->and($summary->outstanding())->toBe(1120.0)   // 600 + 500 + 20
        ->and($summary->overdue())->toBe(620.0)        // 600 due 15/08 + retainer period due 01/11 (today 06/11)
        ->and($summary->paidExpenses())->toBe(123.0)
        ->and($summary->unpaidExpenses())->toBe(50.0)
        ->and($summary->cashResult())->toBe(297.0)
        ->and($summary->forecastResult())->toBe(1367.0); // contracted − (paid + unpaid)
});

it('narrows every figure to the selected month', function () {
    seedPeriodData();

    $march = new PeriodSummary(Period::of(2026, 3));

    expect($march->contracted())->toBe(1020.0)
        ->and($march->received())->toBe(420.0)
        ->and($march->outstanding())->toBe(600.0)
        ->and($march->paidExpenses())->toBe(123.0)
        ->and($march->unpaidExpenses())->toBe(0.0);
});

it('breaks cash flow down by month for a year and by day for a month', function () {
    seedPeriodData();

    $year = (new PeriodSummary(Period::of(2026)))->cashFlow();
    $march = (new PeriodSummary(Period::of(2026, 3)))->cashFlow();

    expect($year)->toHaveCount(12)
        ->and($year[2])->toMatchArray(['label' => 'Mar', 'received' => 420.0, 'paid' => 123.0, 'result' => 297.0])
        ->and($march)->toHaveCount(31)
        ->and($march[1])->toMatchArray(['label' => '2', 'received' => 20.0])
        ->and($march[30])->toMatchArray(['label' => '31', 'received' => 400.0]);
});

it('compares against the previous period', function () {
    seedPeriodData();

    $summary = new PeriodSummary(Period::of(2026));

    expect($summary->previous()->received())->toBe(200.0)
        ->and($summary->change('received')->percent)->toBe(110.0);
});
