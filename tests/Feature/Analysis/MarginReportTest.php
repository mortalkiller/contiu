<?php

use App\Enums\ExpenseKind;
use App\Enums\ProjectStatus;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\Retainer;
use App\Support\MarginReport;
use App\Support\Period;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-06'));

    $this->alpha = Client::factory()->create(['name' => 'Alpha']);
    $this->beta = Client::factory()->create(['name' => 'Beta']);

    $a = Project::factory()->for($this->alpha)->create(['business_line' => 'Printee', 'quoted_on' => '2026-03-01', 'net_amount' => 100000]);
    $aDue = Receivable::factory()->for($a, 'billable')->create(['amount' => 100000, 'due_on' => '2026-04-30']);
    Payment::factory()->for($aDue)->create(['paid_on' => '2026-04-10', 'amount' => 60000]);
    Expense::factory()->create(['kind' => ExpenseKind::Direct, 'project_id' => $a->id, 'spent_on' => '2026-05-02', 'net_amount' => 10000]);

    $b = Project::factory()->for($this->beta)->create(['business_line' => 'Pressiu', 'quoted_on' => '2026-05-01', 'net_amount' => 50000]);
    $bDue = Receivable::factory()->for($b, 'billable')->create(['amount' => 50000, 'due_on' => '2026-06-30']);
    Payment::factory()->for($bDue)->create(['paid_on' => '2026-06-15', 'amount' => 50000]);

    $r = Retainer::factory()->for($this->alpha)->create(['business_line' => 'Pressiu', 'net_amount' => 25550]);
    $rDue = Receivable::factory()->for($r, 'billable')->create(['amount' => 25550, 'due_on' => '2026-08-28']);
    Payment::factory()->for($rDue)->create(['paid_on' => '2026-08-03', 'amount' => 25550]);
    Expense::factory()->create(['kind' => ExpenseKind::Direct, 'retainer_id' => $r->id, 'spent_on' => '2026-07-01', 'net_amount' => 10000, 'vat_rate' => 0.23]);

    $cancelled = Project::factory()->for($this->beta)->create(['quoted_on' => '2026-02-01', 'net_amount' => 20000, 'status' => ProjectStatus::Cancelled]);
    Receivable::factory()->for($cancelled, 'billable')->create(['amount' => 20000, 'due_on' => '2026-03-01']);

    $old = Project::factory()->for($this->alpha)->create(['business_line' => 'Printee', 'quoted_on' => '2025-11-01', 'net_amount' => 30000]);
    $oldDue = Receivable::factory()->for($old, 'billable')->create(['amount' => 30000, 'due_on' => '2025-12-31']);
    Payment::factory()->for($oldDue)->create(['paid_on' => '2026-01-05', 'amount' => 30000]);

    Expense::factory()->create(['kind' => ExpenseKind::Overhead, 'spent_on' => '2026-04-03', 'net_amount' => 15000]);
});

it('breaks the year down by business line', function () {
    $rows = (new MarginReport(Period::of(2026)))->byBusinessLine();

    expect(array_keys($rows))->toBe(['Pressiu', 'Printee'])
        ->and($rows['Printee'])->toMatchArray(['invoiced' => 100000, 'received' => 90000, 'directCosts' => 10000, 'margin' => 90000, 'marginPercent' => 90.0])
        ->and($rows['Pressiu'])->toMatchArray(['invoiced' => 75550, 'received' => 75550, 'directCosts' => 12300, 'margin' => 63250, 'marginPercent' => 83.7]);
});

it('breaks the year down by client', function () {
    $rows = (new MarginReport(Period::of(2026)))->byClient();

    expect(array_keys($rows))->toBe(['Alpha', 'Beta'])
        ->and($rows['Alpha'])->toMatchArray(['invoiced' => 125550, 'received' => 115550, 'directCosts' => 22300, 'margin' => 103250])
        ->and($rows['Beta'])->toMatchArray(['invoiced' => 50000, 'received' => 50000, 'directCosts' => 0, 'margin' => 50000, 'marginPercent' => 100.0]);
});

it('subtracts overhead from the total margin', function () {
    $report = new MarginReport(Period::of(2026));

    expect($report->totals())->toMatchArray(['invoiced' => 175550, 'directCosts' => 22300, 'margin' => 153250, 'overhead' => 15000, 'result' => 138250]);
});

it('narrows to a month and leaves the percentage empty without invoicing', function () {
    $report = new MarginReport(Period::of(2026, 4));

    expect($report->byBusinessLine())->toBe([
        'Printee' => ['label' => 'Printee', 'invoiced' => 0, 'received' => 60000, 'directCosts' => 0, 'margin' => 0, 'marginPercent' => null],
    ])->and($report->totals()['result'])->toBe(-15000);
});

it('groups work without a business line under "Sem linha"', function () {
    $c = Project::factory()->for($this->beta)->create(['business_line' => null, 'quoted_on' => '2026-09-01', 'net_amount' => 8000]);
    Receivable::factory()->for($c, 'billable')->create(['amount' => 8000, 'due_on' => '2026-09-30']);

    expect((new MarginReport(Period::of(2026)))->byBusinessLine())->toHaveKey('Sem linha');
});
