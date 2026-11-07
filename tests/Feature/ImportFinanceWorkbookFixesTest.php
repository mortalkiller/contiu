<?php

use App\Actions\ImportFinanceWorkbook;
use App\Models\Expense;
use App\Models\Project;
use App\Models\RecurringExpense;
use App\Models\Retainer;
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

it('fixes a retainer period whose due date falls before its own month', function () {
    $result = importFixture();

    $site = Retainer::firstWhere('code', 'SITE');

    expect($site->receivables()->orderBy('sort')->pluck('due_on')->map->toDateString()->all())
        ->toBe(['2026-01-01', '2026-02-01', '2026-03-01'])
        ->and(collect($result->warnings)->first(fn ($warning) => str_contains($warning, 'SITE3')))
        ->toContain('corrigi para 01/03/2026');
});

it('keeps an instalment plan made in the app when re-importing', function () {
    importFixture();

    $project = Project::firstWhere('code', 'PRINTEE OC-1');
    $single = $project->receivables()->sole();
    $single->update(['description' => 'Prestação 1/2', 'amount' => 75000, 'due_on' => '2026-03-31']);
    $second = $project->receivables()->create(['description' => 'Prestação 2/2', 'amount' => 165000, 'due_on' => '2026-08-15', 'sort' => 1]);
    $single->payments()->where('amount', 165000)->update(['receivable_id' => $second->id]);

    $result = importFixture(replace: true);

    $plan = Project::firstWhere('code', 'PRINTEE OC-1')->receivables()->with('payments')->orderBy('sort')->get();

    expect($plan->pluck('description')->all())->toBe(['Prestação 1/2', 'Prestação 2/2'])
        ->and($plan->map(fn ($receivable) => $receivable->payments->sum('amount'))->all())->toBe([75000, 165000])
        ->and($result->info)->toContain('Mantive o plano de prestações de 1 trabalho (PRINTEE OC-1).');
});

it('does not keep a plan that no longer adds up to the imported total', function () {
    importFixture();

    $project = Project::firstWhere('code', 'PRINTEE OC-1');
    $project->receivables()->sole()->update(['amount' => 100000]);
    $project->receivables()->create(['description' => 'Extra', 'amount' => 100000, 'due_on' => '2026-09-01']);

    $result = importFixture(replace: true);

    expect(Project::firstWhere('code', 'PRINTEE OC-1')->receivables()->count())->toBe(1)
        ->and(collect($result->warnings)->first(fn ($warning) => str_contains($warning, 'PRINTEE OC-1')))
        ->toContain('plano de prestações');
});

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
});
