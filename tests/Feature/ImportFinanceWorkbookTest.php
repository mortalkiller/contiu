<?php

use App\Actions\ImportRefusedException;
use App\Enums\ExpenseKind;
use App\Enums\Frequency;
use App\Enums\PaymentMethod;
use App\Enums\ProjectStatus;
use App\Filament\Pages\ImportWorkbook;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\Retainer;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

it('imports clients, projects and their single receivable', function () {
    importFixture();

    expect(Client::count())->toBe(2)
        ->and(Project::count())->toBe(3);

    $project = Project::firstWhere('code', 'PRESSIU OC-2');

    expect($project)
        ->title->toBe('Catálogo')
        ->description->toBe("Catálogo\nIntegrações com Moloni")
        ->business_line->toBe('Pressiu')
        ->status->toBe(ProjectStatus::InProgress)
        ->and($project->quoted_on->toDateString())->toBe('2026-11-01')
        ->and($project->receivables()->pluck('description')->all())->toBe(['Pagamento único'])
        ->and($project->receivables()->first()->due_on->toDateString())->toBe('2027-01-31')
        ->and(Project::firstWhere('code', 'PRINTEE OC-3'))
        ->status->toBe(ProjectStatus::Cancelled)
        ->total->toBe(61500);
});

it('turns repeated maintenance rows into a monthly retainer and a single one into a yearly retainer', function () {
    importFixture();

    $monthly = Retainer::firstWhere('code', 'SITE');
    $yearly = Retainer::firstWhere('code', 'INFRA2026');

    expect($monthly)
        ->frequency->toBe(Frequency::Monthly)
        ->due_day->toBe(1)
        ->title->toBe('Alojamento')
        ->and($monthly->starts_on->toDateString())->toBe('2026-01-01')
        ->and($monthly->receivables()->pluck('description')->all())->toBe(['Jan 2026', 'Fev 2026', 'Mar 2026'])
        ->and($yearly)
        ->frequency->toBe(Frequency::Yearly)
        ->due_day->toBe(28)
        ->and($yearly->receivables()->count())->toBe(1);
});

it('allocates payments to the receivable of their row', function () {
    importFixture();

    $project = Project::firstWhere('code', 'PRINTEE OC-1')->load('receivables.payments');
    $site = Retainer::firstWhere('code', 'SITE')->load('receivables.payments');

    expect($project->received_amount)->toBe(240000)
        ->and($project->outstanding_amount)->toBe(0)
        ->and($site->receivables->map(fn (Receivable $r) => $r->received)->all())->toBe([2000, 2000, 0])
        ->and(Payment::firstWhere('amount', 25550))
        ->invoice_number->toBe('FT 1')
        ->method->toBe(PaymentMethod::Transfer)
        ->and(Payment::firstWhere('amount', 165000)->method)->toBe(PaymentMethod::Cash);
});

it('classifies expenses as direct only when tied to a retainer or a direct-cost category', function () {
    importFixture();

    $server = Expense::firstWhere('description', 'Servidor');
    $chatGpt = Expense::firstWhere('description', 'ChatGPT Plus');
    $fee = Expense::firstWhere('description', 'Registo de marca');

    expect($server)
        ->kind->toBe(ExpenseKind::Direct)
        ->retainer_id->toBe(Retainer::firstWhere('code', 'INFRA2026')->id)
        ->and($server->total)->toBe(12202)
        ->and($chatGpt)
        ->kind->toBe(ExpenseKind::Overhead)
        ->project_id->toBeNull()
        ->and($chatGpt->category->name)->toBe('Software/SaaS')
        ->and($fee)
        ->is_paid->toBeFalse()
        ->paid_on->toBeNull()
        ->and($fee->supplier->name)->toBe('INPI');
});

it('reports rows it could not use or that look wrong', function () {
    $result = importFixture();

    expect(implode("\n", $result->warnings))
        ->toContain('NAO-EXISTE')
        ->toContain('SITE3')
        ->and($result->counts['projects'])->toBe(3)
        ->and($result->counts['retainers'])->toBe(2)
        ->and($result->counts['payments'])->toBe(5)
        ->and($result->counts['expenses'])->toBe(3)
        ->and($result->contracted)->toBe(468050)
        ->and($result->received)->toBe(269550);
});

it('refuses to import over existing data unless asked to replace it', function () {
    importFixture();

    expect(fn () => importFixture())->toThrow(ImportRefusedException::class);

    importFixture(replace: true);

    expect(Project::count())->toBe(3)
        ->and(Client::count())->toBe(2)
        ->and(Payment::count())->toBe(5);
});

it('previews without saving anything', function () {
    $result = importFixture(dryRun: true);

    expect($result->counts['projects'])->toBe(3)
        ->and(Project::count())->toBe(0)
        ->and(Client::count())->toBe(0);
});

it('imports from the command line', function () {
    $this->artisan('finance:import-excel', ['path' => financeWorkbook()])
        ->expectsOutputToContain('Trabalhos: 3')
        ->expectsOutputToContain('NAO-EXISTE')
        ->assertSuccessful();

    expect(Project::count())->toBe(3);
});

it('previews and imports from the Importar Excel page', function () {
    actingAsAdmin();

    $file = UploadedFile::fake()->createWithContent('controlo.xlsx', file_get_contents(financeWorkbook()));

    Livewire::test(ImportWorkbook::class)
        ->fillForm(['file' => $file, 'replace' => false])
        ->call('preview')
        ->assertHasNoFormErrors()
        ->assertSet('preview.counts.projects', 3)
        ->call('import')
        ->assertNotified();

    expect(Project::count())->toBe(3);
});
