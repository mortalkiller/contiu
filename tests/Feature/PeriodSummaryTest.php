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
use Illuminate\Support\Facades\DB;

pest()->group('integration');

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
    expect(Change::between(15000, 10000)->percent)->toBe(50.0)
        ->and(Change::between(5000, 10000)->percent)->toBe(-50.0)
        ->and(Change::between(0, 0)->percent)->toBe(0.0)
        ->and(Change::between(8000, 0)->percent)->toBeNull()
        ->and(Change::between(8000, 0)->label())->toBe('sem valor no período anterior')
        ->and(Change::between(15000, 10000)->label())->toBe('+50%')
        ->and(Change::between(-2000, 10000)->label())->toBe('−120%');
});

// Resumo do período -------------------------------------------------------------------------

function seedPeriodData(): void
{
    $project = Project::factory()->create(['quoted_on' => '2026-03-04', 'net_amount' => 100000, 'vat_rate' => 0]);
    $instalment = Receivable::factory()->for($project, 'billable')->create(['amount' => 100000, 'due_on' => '2026-08-15']);
    Payment::factory()->for($instalment)->create(['paid_on' => '2026-03-31', 'amount' => 40000]);

    $cancelled = Project::factory()->create(['quoted_on' => '2026-05-01', 'net_amount' => 99900, 'status' => ProjectStatus::Cancelled]);
    Receivable::factory()->for($cancelled, 'billable')->create(['amount' => 99900, 'due_on' => '2026-06-01']);

    $nextYearJob = Project::factory()->create(['quoted_on' => '2026-11-01', 'net_amount' => 50000]);
    Receivable::factory()->for($nextYearJob, 'billable')->create(['amount' => 50000, 'due_on' => '2027-01-31']);

    $retainer = Retainer::factory()->create();
    $march = Receivable::factory()->for($retainer, 'billable')->create(['amount' => 2000, 'due_on' => '2026-03-01']);
    Payment::factory()->for($march)->create(['paid_on' => '2026-03-02', 'amount' => 2000]);
    Receivable::factory()->for($retainer, 'billable')->create(['amount' => 2000, 'due_on' => '2026-11-01']);

    Expense::factory()->create(['spent_on' => '2026-03-10', 'net_amount' => 10000, 'vat_rate' => 0.23]);
    Expense::factory()->unpaid()->create(['spent_on' => '2026-11-02', 'net_amount' => 5000]);
    $lastYear = Project::factory()->create(['quoted_on' => '2025-03-01', 'net_amount' => 20000]);
    $lastYearDue = Receivable::factory()->for($lastYear, 'billable')->create(['amount' => 20000, 'due_on' => '2025-03-05']);
    Payment::factory()->for($lastYearDue)->create(['paid_on' => '2025-03-05', 'amount' => 20000]);
}

it('sums the year like the Excel summary', function () {
    seedPeriodData();

    $summary = new PeriodSummary(Period::of(2026));

    expect($summary->contracted())->toBe(154000)       // 1000 + 500 (quoted in 2026) + 2 × 20, cancelled excluded
        ->and($summary->received())->toBe(42000)
        ->and($summary->outstanding())->toBe(112000)   // 600 + 500 + 20
        ->and($summary->overdue())->toBe(62000)        // 600 due 15/08 + retainer period due 01/11 (today 06/11)
        ->and($summary->paidExpenses())->toBe(12300)
        ->and($summary->unpaidExpenses())->toBe(5000)
        ->and($summary->cashResult())->toBe(29700)
        ->and($summary->forecastResult())->toBe(136700); // contracted − (paid + unpaid)
});

it('narrows every figure to the selected month', function () {
    seedPeriodData();

    $march = new PeriodSummary(Period::of(2026, 3));

    expect($march->contracted())->toBe(102000)
        ->and($march->received())->toBe(42000)
        ->and($march->outstanding())->toBe(60000)
        ->and($march->paidExpenses())->toBe(12300)
        ->and($march->unpaidExpenses())->toBe(0);
});

it('breaks cash flow down by month for a year and by day for a month', function () {
    seedPeriodData();

    $year = (new PeriodSummary(Period::of(2026)))->cashFlow();
    $march = (new PeriodSummary(Period::of(2026, 3)))->cashFlow();

    expect($year)->toHaveCount(12)
        ->and($year[2])->toMatchArray(['label' => 'Mar', 'received' => 42000, 'paid' => 12300, 'result' => 29700])
        ->and($march)->toHaveCount(31)
        ->and($march[1])->toMatchArray(['label' => '2', 'received' => 2000])
        ->and($march[30])->toMatchArray(['label' => '31', 'received' => 40000]);
});

it('compares against the previous period', function () {
    seedPeriodData();

    $summary = new PeriodSummary(Period::of(2026));

    expect($summary->previous()->received())->toBe(20000)
        ->and($summary->change('received')->percent)->toBe(110.0);
});

it('keeps the balance query count independent of the number of receivables', function () {
    $project = Project::factory()->create(['quoted_on' => '2026-03-04']);
    $charges = Receivable::factory()->count(8)->for($project, 'billable')
        ->create(['amount' => 10000, 'due_on' => '2026-04-01']);
    foreach ($charges as $charge) {
        Payment::factory()->for($charge)->create(['paid_on' => '2026-03-31', 'amount' => 2500]);
    }

    $summary = new PeriodSummary(Period::of(2026));
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $outstanding = $summary->outstanding();
        $overdue = $summary->overdue();
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
    }

    expect($outstanding)->toBe(60000)
        ->and($overdue)->toBe(60000)
        ->and($queries)->toHaveCount(1);
})->group('performance');
