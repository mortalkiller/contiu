<?php

use App\Enums\ProjectStatus;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-10'));
    actingAsAdmin();

    $this->running = Project::factory()->create([
        'title' => 'Landing page', 'code' => 'DEMO-05', 'business_line' => 'Web',
        'status' => ProjectStatus::InProgress, 'quoted_on' => '2026-11-02', 'net_amount' => 80000, 'vat_rate' => 0,
    ]);
    $deposit = Receivable::factory()->for($this->running, 'billable')->create(['amount' => 40000, 'due_on' => '2026-11-05']);
    Receivable::factory()->for($this->running, 'billable')->create(['amount' => 40000, 'due_on' => '2026-12-15']);
    Payment::factory()->for($deposit)->create(['amount' => 40000, 'paid_on' => '2026-11-05']);

    $this->proposal = Project::factory()->create([
        'title' => 'Rebranding', 'status' => ProjectStatus::Proposal, 'quoted_on' => '2026-10-29', 'net_amount' => 90000, 'vat_rate' => 0,
    ]);
    Project::factory()->create(['status' => ProjectStatus::Completed, 'quoted_on' => '2025-12-01', 'net_amount' => 50000, 'vat_rate' => 0]);
});

it('says how many projects there are and what this year\'s quotes add up to, with no breadcrumbs', function () {
    Livewire::test(ListProjects::class)
        ->assertSee('3 trabalhos · '.Money::format(170000).' em orçamentos de 2026', escape: false)
        ->assertDontSeeHtml('fi-breadcrumbs')
        ->assertActionExists('export')
        ->assertActionExists('create');
})->group('ui');

it('lists each project with its code and line under the title, how much came in and the totals at the foot', function () {
    Livewire::test(ListProjects::class)
        ->loadTable()
        ->assertSeeInOrder(['Landing page', 'DEMO-05', 'Web'])
        ->assertSee('Ainda não aprovado')
        ->assertSee('50%')
        ->assertTableColumnSummarySet('total', 'sum', 220000)
        ->assertTableColumnSummarySet('outstanding_amount', 'sum', 40000);
})->group('ui');

it('offers to receive on a project with something open and to approve a proposal', function () {
    Livewire::test(ListProjects::class)
        ->loadTable()
        ->assertTableActionVisible('recordPayment', $this->running)
        ->assertTableActionHidden('approve', $this->running)
        ->assertTableActionHidden('recordPayment', $this->proposal)
        ->callTableAction('approve', $this->proposal);

    expect($this->proposal->refresh()->status)->toBe(ProjectStatus::Approved);
})->group('ui');
