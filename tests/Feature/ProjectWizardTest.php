<?php

use App\Enums\ProjectStatus;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Models\Client;
use App\Models\Project;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-03-10'));
    actingAsAdmin();
});

function preset(string $name): TestAction
{
    return TestAction::make($name)->schemaComponent('planPresets', 'form');
}

function wizardAtPlanStep(Client $client)
{
    return Livewire::test(CreateProject::class)
        ->fillForm(['client_id' => $client->id, 'business_line' => 'Printee'])
        ->goToWizardStep(2)
        ->assertHasNoFormErrors()
        ->fillForm([
            'code' => 'OC-202603-9',
            'status' => ProjectStatus::Approved,
            'title' => 'Loja online',
            'quoted_on' => '2026-03-10',
            'net_amount' => 1000,
            'vat_rate' => '0.0000',
        ])
        ->goToWizardStep(3)
        ->assertHasNoFormErrors()
        ->assertWizardCurrentStep(3);
}

it('creates a project in steps using the equal instalments preset', function () {
    $client = Client::factory()->create();

    wizardAtPlanStep($client)
        ->callAction(preset('planEqual'))
        ->goToWizardStep(4)
        ->assertHasNoFormErrors()
        ->assertWizardCurrentStep(4)
        ->call('create')
        ->assertHasNoFormErrors();

    $project = Project::firstWhere('code', 'OC-202603-9');

    expect($project->client_id)->toBe($client->id)
        ->and($project->receivables()->orderBy('due_on')->get(['description', 'amount', 'due_on'])->map(fn ($row) => [$row->description, $row->amount, $row->due_on->toDateString()])->all())
        ->toBe([
            ['Prestação 1/3', 33333, '2026-03-10'],
            ['Prestação 2/3', 33333, '2026-04-10'],
            ['Prestação 3/3', 33334, '2026-05-10'],
        ]);
});

it('fills a monthly plan up to a chosen last due date', function () {
    wizardAtPlanStep(Client::factory()->create())
        ->callAction(preset('planMonthly'), data: ['last_due_on' => '2026-06-10'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Project::firstWhere('code', 'OC-202603-9')->receivables()->count())->toBe(4);
});

it('does not leave the plan step while the instalments do not add up', function () {
    wizardAtPlanStep(Client::factory()->create())
        ->set('data.receivables', [['description' => 'Parte', 'amount' => 100, 'due_on' => '2026-03-20']])
        ->goToWizardStep(4)
        ->assertHasFormErrors(['receivables'])
        ->assertWizardCurrentStep(3);
});

it('starts with the client chosen when opened from a client page', function () {
    $client = Client::factory()->create();

    Livewire::withQueryParams(['client' => $client->id])
        ->test(CreateProject::class)
        ->assertSchemaStateSet(['client_id' => $client->id]);
});

it('keeps the full form on the edit page', function () {
    $project = Project::factory()->create(['net_amount' => 50000, 'quoted_on' => '2026-03-01']);
    $project->receivables()->create(['description' => 'Pagamento único', 'amount' => 50000, 'due_on' => '2026-03-31']);

    Livewire::test(EditProject::class, ['record' => $project->getRouteKey()])
        ->assertFormFieldExists('client_id')
        ->assertFormFieldExists('receivables')
        ->assertFormFieldExists('notes')
        ->fillForm(['title' => 'Título novo'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($project->fresh()->title)->toBe('Título novo');
});
