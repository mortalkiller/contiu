<?php

use App\Enums\ExpenseKind;
use App\Enums\ProjectStatus;
use App\Exceptions\HasPaymentsException;
use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Retainers\Pages\EditRetainer;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\Retainer;
use App\Models\Supplier;
use Livewire\Livewire;

beforeEach(fn () => actingAsAdmin());

function projectFormData(array $overrides = []): array
{
    return [
        'code' => 'OC-1',
        'status' => ProjectStatus::Approved,
        'client_id' => Client::factory()->create()->id,
        'title' => 'Site',
        'quoted_on' => '2026-03-01',
        'net_amount' => 1000,
        'vat_rate' => '0.0000',
        ...$overrides,
    ];
}

// Trabalhos ---------------------------------------------------------------------------------

it('saves a project whose instalments add up to the total', function () {
    Livewire::test(CreateProject::class)
        ->fillForm(projectFormData())
        ->set('data.receivables', [
            ['description' => '1/2', 'amount' => 400, 'due_on' => '2026-03-15'],
            ['description' => '2/2', 'amount' => 600, 'due_on' => '2026-04-15'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Project::firstWhere('code', 'OC-1')->receivables()->sum('amount'))->toEqual(1000);
});

it('refuses instalments that do not add up to the project total', function () {
    Livewire::test(CreateProject::class)
        ->fillForm(projectFormData())
        ->set('data.receivables', [
            ['description' => '1/2', 'amount' => 400, 'due_on' => '2026-03-15'],
        ])
        ->call('create')
        ->assertHasFormErrors(['receivables']);

    expect(Project::count())->toBe(0);
});

it('refuses an instalment due before the quote date', function () {
    Livewire::test(CreateProject::class)
        ->fillForm(projectFormData())
        ->set('data.receivables', [
            ['description' => 'Único', 'amount' => 1000, 'due_on' => '2026-02-01'],
        ])
        ->call('create')
        ->assertHasFormErrors(['receivables']);
});

// Recebimentos ------------------------------------------------------------------------------

it('refuses a payment larger than what is still open', function () {
    $receivable = Receivable::factory()->create(['amount' => 300]);
    Payment::factory()->for($receivable)->create(['amount' => 100]);

    Livewire::test(ManagePayments::class)
        ->callAction('recordPayment', data: [
            'receivable_id' => $receivable->id,
            'paid_on' => today()->toDateString(),
            'amount' => 250,
            'method' => 'transfer',
        ])
        ->assertHasActionErrors(['amount']);

    expect($receivable->payments()->count())->toBe(1);
});

it('records a payment that settles what is open', function () {
    $receivable = Receivable::factory()->create(['amount' => 300]);

    Livewire::test(ManagePayments::class)
        ->callAction('recordPayment', data: [
            'receivable_id' => $receivable->id,
            'paid_on' => today()->toDateString(),
            'amount' => 300,
            'method' => 'transfer',
            'invoice_number' => 'FR 2026/1',
        ])
        ->assertHasNoActionErrors();

    expect($receivable->fresh()->outstanding)->toBe(0.0)
        ->and($receivable->payments()->first()->invoice_number)->toBe('FR 2026/1');
});

// Apagar ------------------------------------------------------------------------------------

it('deletes a project together with its unpaid instalments', function () {
    $project = Project::factory()->create();
    Receivable::factory()->for($project, 'billable')->create();

    $project->delete();

    expect(Receivable::count())->toBe(0);
});

it('refuses to delete a project, retainer or receivable that already has payments', function (string $what) {
    $owner = $what === 'retainer' ? Retainer::factory()->create() : Project::factory()->create();
    $receivable = Receivable::factory()->for($owner, 'billable')->create();
    Payment::factory()->for($receivable)->create();

    $target = $what === 'receivable' ? $receivable : $owner;

    expect(fn () => $target->delete())->toThrow(HasPaymentsException::class);
    expect($target->fresh())->not->toBeNull();
})->with(['project', 'retainer', 'receivable']);

// Despesas ----------------------------------------------------------------------------------

it('requires a project or retainer for a direct expense', function () {
    Livewire::test(CreateExpense::class)
        ->fillForm([
            'supplier_id' => Supplier::factory()->create()->id,
            'description' => 'Servidor',
            'spent_on' => today()->toDateString(),
            'net_amount' => 10,
            'vat_rate' => '0.0000',
            'kind' => ExpenseKind::Direct->value,
            'is_paid' => true,
            'paid_on' => today()->toDateString(),
            'method' => 'card',
        ])
        ->call('create')
        ->assertHasFormErrors(['project_id']);
});

it('hides delete on a project that already has payments', function () {
    $project = Project::factory()->create();
    Payment::factory()->for(Receivable::factory()->for($project, 'billable'))->create();

    Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
        ->assertActionHidden('delete');
});

it('hides delete on a retainer that already has payments', function () {
    $retainer = Retainer::factory()->create();
    Payment::factory()->for(Receivable::factory()->for($retainer, 'billable'))->create();

    Livewire::test(EditRetainer::class, ['record' => $retainer->getRouteKey()])
        ->assertActionHidden('delete');
});
