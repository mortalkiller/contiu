<?php

use App\Enums\ExpenseKind;
use App\Enums\ProjectStatus;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\RelationManagers\ProjectDocumentsRelationManager;
use App\Filament\Resources\Projects\RelationManagers\ProjectExpensesRelationManager;
use App\Filament\Resources\Projects\RelationManagers\ProjectPaymentsRelationManager;
use App\Filament\Resources\Projects\RelationManagers\ProjectReceivablesRelationManager;
use App\Filament\Resources\Projects\Schemas\ProjectTimeline;
use App\Models\Expense;
use App\Models\FiscalDocument;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-10'));
    actingAsAdmin();

    $this->project = Project::factory()->create([
        'title' => 'Correção por rever', 'status' => ProjectStatus::InProgress,
        'quoted_on' => '2026-09-01', 'net_amount' => 80000, 'vat_rate' => 0,
    ]);
    $this->deposit = Receivable::factory()->for($this->project, 'billable')->create(['description' => 'Sinal de 50%', 'amount' => 40000, 'due_on' => '2026-09-15']);
    $this->draft = Receivable::factory()->for($this->project, 'billable')->create(['description' => 'Entrega da 1.ª versão', 'amount' => 25000, 'due_on' => '2026-10-10']);
    $this->late = Receivable::factory()->for($this->project, 'billable')->create(['description' => 'Revisão', 'amount' => 5000, 'due_on' => '2026-11-01']);
    Receivable::factory()->for($this->project, 'billable')->create(['description' => 'Fecho do trabalho', 'amount' => 10000, 'due_on' => '2027-02-23']);
    Payment::factory()->for($this->deposit)->create(['amount' => 40000, 'paid_on' => '2026-09-15']);
    Payment::factory()->for($this->draft)->create(['amount' => 25000, 'paid_on' => '2026-10-14']);
    Expense::factory()->create(['kind' => ExpenseKind::Direct, 'project_id' => $this->project->id, 'spent_on' => '2026-09-20', 'net_amount' => 38000, 'vat_rate' => 0]);

    $this->project = ProjectResource::getEloquentQuery()->findOrFail($this->project->id);
});

it('tells the story of a project: the quote, what came in and when, and what is still due', function () {
    expect(ProjectTimeline::events($this->project))->toMatchArray([
        ['title' => 'Orçamento aprovado', 'meta' => 'Orçamento de 01/09/2026', 'amount' => null, 'state' => 'done'],
        ['title' => 'Recebido: Sinal de 50%', 'meta' => '15/09/2026 · no dia do vencimento', 'amount' => Money::format(40000), 'state' => 'done'],
        ['title' => 'Recebido: Entrega da 1.ª versão', 'meta' => '14/10/2026 · 4 dias depois', 'amount' => Money::format(25000), 'state' => 'done'],
        ['title' => 'Revisão', 'meta' => 'Venceu a 01/11/2026 · há 9 dias', 'amount' => Money::format(5000), 'state' => 'late'],
        ['title' => 'Fecho do trabalho', 'meta' => 'Vence a 23/02/2027', 'amount' => Money::format(10000), 'state' => 'pending'],
    ]);
})->group('integration');

it('shows the timeline, the details and a note under each figure on the project page', function () {
    Livewire::test(ViewProject::class, ['record' => $this->project->getRouteKey()])
        ->assertSee('Linha do tempo')
        ->assertSee('Recebido: Sinal de 50%')
        ->assertSee('Detalhes')
        ->assertSee('Sem IVA')
        ->assertSee('81% do faturado')
        ->assertSee('Venceu a 01/11/2026')
        ->assertSee('52,5% · '.Money::format(38000).' de custos diretos', escape: false)
        ->assertActionExists('recordPayment')
        ->assertActionHidden('issueInvoice');
})->group('ui');

it('counts the records on each tab and lists the fiscal documents of the project', function () {
    $invoice = FiscalDocument::factory()->create(['number' => 'FT 2026/7']);
    $invoice->links()->create(['receivable_id' => $this->deposit->id]);
    $other = FiscalDocument::factory()->create(['number' => 'FT 2026/99']);

    expect(ProjectReceivablesRelationManager::getBadge($this->project, ViewProject::class))->toBe('4')
        ->and(ProjectPaymentsRelationManager::getBadge($this->project, ViewProject::class))->toBe('2')
        ->and(ProjectExpensesRelationManager::getBadge($this->project, ViewProject::class))->toBe('1')
        ->and(ProjectDocumentsRelationManager::getBadge($this->project, ViewProject::class))->toBe('1')
        ->and(ProjectReceivablesRelationManager::getTitle($this->project, ViewProject::class))->toBe('Valores a receber');

    Livewire::test(ProjectDocumentsRelationManager::class, ['ownerRecord' => $this->project, 'pageClass' => ViewProject::class])
        ->loadTable()
        ->assertCanSeeTableRecords([$invoice])
        ->assertCanNotSeeTableRecords([$other]);
})->group('ui');
