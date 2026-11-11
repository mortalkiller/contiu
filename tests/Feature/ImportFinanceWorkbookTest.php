<?php

use App\Actions\ImportFinanceWorkbook;
use App\Actions\ImportRefusedException;
use App\Enums\ExpenseKind;
use App\Enums\Frequency;
use App\Enums\PaymentMethod;
use App\Enums\ProjectStatus;
use App\Filament\Pages\ImportWorkbook;
use App\Models\Client;
use App\Models\Expense;
use App\Models\FiscalDocument;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\RecurringExpense;
use App\Models\Retainer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** The base fixture (see ImportFinanceWorkbookTest) plus extra Despesas rows. */
function financeWorkbookWithExpenses(array $extraExpenses): string
{
    $path = financeWorkbook();
    $spreadsheet = IOFactory::load($path);
    $sheet = $spreadsheet->getSheetByName('Despesas');
    $row = $sheet->getHighestDataRow() + 1;

    foreach ($extraExpenses as [$date, $supplier, $category, $description, $net, $vat, $paid, $method]) {
        $sheet->fromArray([[ExcelDate::PHPToExcel(new DateTime($date)), $supplier, $category, $description, $net, $vat, null, $paid, $method]], null, 'A'.$row++, true);
    }

    (new Xlsx($spreadsheet))->save($path);

    return $path;
}

describe('Initial import', function () {
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
    })->group('integration');

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
    })->group('integration');

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
    })->group('integration');

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
    })->group('integration');

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
    })->group('integration');

    it('fixes a retainer period whose due date falls before its own month', function () {
        $result = importFixture();

        $site = Retainer::firstWhere('code', 'SITE');

        expect($site->receivables()->orderBy('sort')->pluck('due_on')->map->toDateString()->all())
            ->toBe(['2026-01-01', '2026-02-01', '2026-03-01'])
            ->and(collect($result->warnings)->first(fn ($warning) => str_contains($warning, 'SITE3')))
            ->toContain('corrigi para 01/03/2026');
    })->group('integration');

    it('turns expenses that repeat in three or more months into a recurring expense', function () {
        $path = financeWorkbookWithExpenses([
            ['2026-08-27', 'OpenAI', 'ChatGPT', 'ChatGPT Pro', 83.74, 0, 'Sim', 'Cartão'],
            ['2026-09-27', 'OpenAI', 'ChatGPT', 'ChatGPT Pro', 83.74, 0, 'Sim', 'Cartão'],
            ['2026-10-27', 'OpenAI', 'ChatGPT', 'ChatGPT Pro', 83.74, 0, 'Sim', 'Cartão'],
            ['2026-10-30', 'OpenAI', 'ChatGPT', 'Créditos API', 10, 0, 'Sim', 'Cartão'],
        ]);

        $result = app(ImportFinanceWorkbook::class)->run($path);

        $template = RecurringExpense::sole();

        expect($template)
            ->description->toBe('ChatGPT Pro')
            ->day->toBe(27)
            ->is_active->toBeTrue()
            ->and($template->net_amount)->toBe(8374)
            ->and($template->starts_on->toDateString())->toBe('2026-08-27')
            ->and(Expense::where('recurring_expense_id', $template->id)->count())->toBe(3)
            ->and($result->counts['recurring_expenses'])->toBe(1);
    })->group('integration');
});

describe('Reimport and data protection', function () {
    it('refuses to import over existing data unless asked to replace it', function () {
        importFixture();

        expect(fn () => importFixture())->toThrow(ImportRefusedException::class);

        importFixture(replace: true);

        expect(Project::count())->toBe(3)
            ->and(Client::count())->toBe(2)
            ->and(Payment::count())->toBe(5);
    })->group('integration');

    it('refuses to replace workbook data linked to fiscal documents', function () {
        importFixture();
        $payment = Payment::query()->firstOrFail();
        FiscalDocument::factory()->create()->links()->create(['payment_id' => $payment->id]);

        expect(fn () => importFixture(replace: true))
            ->toThrow(ImportRefusedException::class, 'documentos fiscais');

        expect(Payment::count())->toBe(5)
            ->and($payment->fresh())->not->toBeNull()
            ->and(FiscalDocument::count())->toBe(1);
    })->group('integration');

    it('refuses to replace expenses with private proofs', function () {
        importFixture();
        $expense = Expense::query()->firstOrFail();
        $expense->forceFill(['proof_path' => 'expense-proofs/'.$expense->account_id.'/'.Str::uuid().'.pdf'])->save();

        expect(fn () => importFixture(replace: true))
            ->toThrow(ImportRefusedException::class, 'comprovativos');

        expect($expense->fresh()->proof_path)->toBe($expense->proof_path);
    })->group('integration');

    it('refuses to discard manual data that is absent from the workbook', function (string $model, array $data) {
        importFixture();
        $record = $model::query()->firstOrFail();
        $record->forceFill($data)->save();

        expect(fn () => importFixture(replace: true))->toThrow(ImportRefusedException::class, 'dados manuais');
        expect($record->fresh())->not->toBeNull();
    })->with([
        'client' => [Client::class, ['fiscal_country_code' => 'ES']],
        'expense' => [Expense::class, ['is_reviewed' => true]],
    ])->group('integration');

    it('keeps an instalment plan made in the app when re-importing', function () {
        importFixture();

        $project = Project::firstWhere('code', 'PRINTEE OC-1');
        $single = $project->receivables()->sole();
        $single->update(['description' => 'Prestação 1/2', 'amount' => 75000, 'due_on' => '2026-03-31']);
        $second = $project->receivables()->create(['description' => 'Prestação 2/2', 'amount' => 165000, 'due_on' => '2026-08-15', 'sort' => 1]);
        $single->payments()->where('payments.amount', 165000)->firstOrFail()->update(['receivable_id' => $second->id]);

        $result = importFixture(replace: true);

        $plan = Project::firstWhere('code', 'PRINTEE OC-1')->receivables()->with('payments')->orderBy('sort')->get();

        expect($plan->pluck('description')->all())->toBe(['Prestação 1/2', 'Prestação 2/2'])
            ->and($plan->map(fn ($receivable) => $receivable->payments->sum('amount'))->all())->toBe([75000, 165000])
            ->and($result->info)->toContain('Mantive o plano de prestações de 1 trabalho (PRINTEE OC-1).');
    })->group('integration');

    it('recognizes the English default for a single payment when re-importing', function () {
        importFixture();

        $single = Project::firstWhere('code', 'PRINTEE OC-1')->receivables()->sole();
        $originalDate = $single->due_on->toDateString();
        $single->update(['description' => 'Single payment', 'due_on' => '2027-09-09']);

        importFixture(replace: true);

        expect(Project::firstWhere('code', 'PRINTEE OC-1')->receivables()->sole()->due_on->toDateString())->toBe($originalDate);
    })->group('integration');

    it('does not keep a plan that no longer adds up to the imported total', function () {
        importFixture();

        $project = Project::firstWhere('code', 'PRINTEE OC-1');
        $project->receivables()->sole()->update(['amount' => 100000]);
        $project->receivables()->create(['description' => 'Extra', 'amount' => 100000, 'due_on' => '2026-09-01']);

        $result = importFixture(replace: true);

        expect(Project::firstWhere('code', 'PRINTEE OC-1')->receivables()->count())->toBe(1)
            ->and(collect($result->warnings)->first(fn ($warning) => str_contains($warning, 'PRINTEE OC-1')))
            ->toContain('plano de prestações');
    })->group('integration');
});

describe('Import entry points', function () {
    it('previews without saving anything', function () {
        $result = importFixture(dryRun: true);

        expect($result->counts['projects'])->toBe(3)
            ->and(Project::count())->toBe(0)
            ->and(Client::count())->toBe(0);
    })->group('integration');

    it('imports from the command line', function () {
        $this->artisan('finance:import-excel', ['path' => financeWorkbook(), '--account' => $this->account->slug])
            ->expectsOutputToContain('Trabalhos: 3')
            ->expectsOutputToContain('NAO-EXISTE')
            ->assertSuccessful();

        expect(Project::count())->toBe(3);
    })->group('integration');

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
    })->group('ui');

    it('refuses a workbook path named from the browser', function () {
        actingAsAdmin();
        Storage::fake('local');
        Storage::disk('local')->put('outra-pasta/controlo.xlsx', file_get_contents(financeWorkbook()));

        Livewire::test(ImportWorkbook::class)
            ->set('data.file', ['forjado' => 'outra-pasta/controlo.xlsx'])
            ->call('preview')
            ->assertHasFormErrors(['file']);

        expect(Project::count())->toBe(0);
    })->group('ui');

    it('requires a fresh preview if the replace option changes', function () {
        actingAsAdmin();
        $file = UploadedFile::fake()->createWithContent('controlo.xlsx', file_get_contents(financeWorkbook()));

        $page = Livewire::test(ImportWorkbook::class)
            ->fillForm(['file' => $file, 'replace' => false])
            ->call('preview')
            ->assertSet('preview.counts.projects', 3);
        $existing = Project::factory()->create();

        $page->set('data.replace', true)
            ->call('import')
            ->assertHasFormErrors(['replace']);

        expect(Project::count())->toBe(1)
            ->and($existing->fresh())->not->toBeNull();
    })->group('ui');

    it('refuses a stored workbook path set from the browser', function () {
        actingAsAdmin();

        Livewire::test(ImportWorkbook::class)->set('storedFile', 'outra-pasta/controlo.xlsx');
    })->throws(CannotUpdateLockedPropertyException::class)->group('ui');
});
