<?php

use App\Actions\RecordOneOffIncome;
use App\Enums\BankTransactionStatus;
use App\Filament\Pages\BankStatementImport;
use App\Filament\Pages\MonthClose;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Client;
use App\Models\Expense;
use App\Models\OneOffIncome;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\Supplier;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-06'));
    Storage::fake('local');
    actingAsAdmin();
});

function statementCsv(): string
{
    return "Data mov.;Descrição;Débito;Crédito\n23-10-2026;TRF GONCALO TEIXEIRA;;730,00\n27-10-2026;COMPRA OPENAI CHATGPT;83,74;\n";
}

function openReceivableFor(string $name, float $amount): Receivable
{
    $project = Project::factory()->for(Client::factory()->create(['name' => $name]))->create(['net_amount' => $amount]);

    return Receivable::factory()->for($project, 'billable')->create(['amount' => $amount, 'due_on' => '2026-10-31']);
}

it('uploads a statement and lists its lines with suggestions', function () {
    openReceivableFor('Gonçalo Teixeira', 730);

    Livewire::test(BankStatementImport::class)
        ->callAction('upload', data: ['file' => UploadedFile::fake()->createWithContent('extrato.csv', statementCsv())])
        ->assertHasNoActionErrors()
        ->assertNotified('2 linhas novas do extrato')
        ->assertCanSeeTableRecords(BankTransaction::all())
        ->assertSee('Recebimento')
        ->assertSee('Sem sugestão');

    expect(BankTransaction::pending()->count())->toBe(2);
});

it('accepts a suggestion with one click', function () {
    $receivable = openReceivableFor('Gonçalo Teixeira', 730);
    $line = BankTransaction::create(['hash' => 'x1', 'booked_on' => '2026-10-23', 'description' => 'TRF GONCALO TEIXEIRA', 'amount' => 730, 'import_batch' => 'b']);

    Livewire::test(BankStatementImport::class)
        ->callTableAction('accept', $line)
        ->assertNotified();

    expect($receivable->payments()->count())->toBe(1)
        ->and($line->fresh()->status)->toBe(BankTransactionStatus::Matched);
});

it('lets you choose among the possible matches', function () {
    openReceivableFor('Cliente Alfa', 20);
    $beta = openReceivableFor('Cliente Beta', 20);
    $line = BankTransaction::create(['hash' => 'x2', 'booked_on' => '2026-11-04', 'description' => 'MBWAY 912', 'amount' => 20, 'import_batch' => 'b']);

    Livewire::test(BankStatementImport::class)
        ->assertTableActionHidden('accept', $line)
        ->callTableAction('link', $line, data: ['targets' => ['receivable:'.$beta->id]]);

    expect($beta->payments()->count())->toBe(1);
});

it('creates an expense from a debit and ignores another line', function () {
    $supplier = Supplier::factory()->create(['name' => 'OpenAI']);
    $debit = BankTransaction::create(['hash' => 'x3', 'booked_on' => '2026-10-27', 'description' => 'COMPRA OPENAI', 'amount' => -83.74, 'import_batch' => 'b']);
    $transfer = BankTransaction::create(['hash' => 'x4', 'booked_on' => '2026-10-28', 'description' => 'TRF ENTRE CONTAS', 'amount' => -500, 'import_batch' => 'b']);

    Livewire::test(BankStatementImport::class)
        ->callTableAction('createExpense', $debit, data: [
            'supplier_id' => $supplier->id,
            'description' => 'ChatGPT Pro',
            'kind' => 'overhead',
            'vat_rate' => '0.0000',
        ])
        ->assertHasNoTableActionErrors()
        ->callTableAction('ignore', $transfer);

    expect(Expense::sole())->description->toBe('ChatGPT Pro')->total->toBe(83.74)
        ->and($transfer->fresh()->status)->toBe(BankTransactionStatus::Ignored);
});

it('accepts every unambiguous suggestion at once', function () {
    openReceivableFor('Gonçalo Teixeira', 730);
    openReceivableFor('Mariana Sousa', 20);
    $lines = collect([
        BankTransaction::create(['hash' => 'y1', 'booked_on' => '2026-10-23', 'description' => 'TRF GONCALO TEIXEIRA', 'amount' => 730, 'import_batch' => 'b']),
        BankTransaction::create(['hash' => 'y2', 'booked_on' => '2026-11-04', 'description' => 'MBWAY MARIANA SOUSA', 'amount' => 20, 'import_batch' => 'b']),
        BankTransaction::create(['hash' => 'y3', 'booked_on' => '2026-11-05', 'description' => 'TRF DESCONHECIDA', 'amount' => 999, 'import_batch' => 'b']),
    ]);

    Livewire::test(BankStatementImport::class)
        ->callTableBulkAction('acceptSuggestions', $lines);

    expect(Payment::count())->toBe(2)
        ->and(BankTransaction::pending()->count())->toBe(1);
});

it('lets you link a line to a registered expense and warns when the amounts differ', function () {
    $expense = Expense::factory()->for(Supplier::factory()->create(['name' => 'INPI']))->create(['net_amount' => 151.98, 'spent_on' => '2026-10-06', 'paid_on' => '2026-10-06']);
    $line = BankTransaction::create(['hash' => 'z1', 'booked_on' => '2026-10-06', 'description' => 'TRF P/ Ordenado', 'amount' => -151, 'import_batch' => 'b']);

    Livewire::test(BankStatementImport::class)
        ->mountTableAction('link', $line)
        ->fillForm(['targets' => ['paid:'.$expense->id]])
        ->assertSchemaComponentExists('amountDifference', checkComponentUsing: fn ($callout) => ! $callout->isHidden() && str_contains($callout->getDescription(), Money::format(0.98)))
        ->callMountedTableAction()
        ->assertHasTableActionErrors(['explanation' => 'required'])
        ->fillForm(['explanation' => 'other_account'])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($line->fresh()->records()->sole()->is($expense))->toBeTrue()
        ->and(Expense::count())->toBe(1);
});

it('links a line to two receivables of a client who paid both at once', function () {
    $first = openReceivableFor('Gonçalo Teixeira', 300);
    $second = Receivable::factory()->for($first->billable, 'billable')->create(['amount' => 200, 'due_on' => '2026-11-30']);
    $line = BankTransaction::create(['hash' => 'w1', 'booked_on' => '2026-11-04', 'description' => 'TRF GONCALO TEIXEIRA', 'amount' => 500, 'import_batch' => 'b']);

    Livewire::test(BankStatementImport::class)
        ->callTableAction('link', $line, data: ['targets' => ['receivable:'.$first->id, 'receivable:'.$second->id]])
        ->assertHasNoTableActionErrors();

    expect($first->fresh()->outstanding)->toBe(0.0)
        ->and($second->fresh()->outstanding)->toBe(0.0)
        ->and($line->fresh()->difference())->toBe(0.0)
        ->and($line->links()->count())->toBe(2);
});

it('links a platform payout to a donation and its commission registered before', function () {
    $category = Category::factory()->create(['type' => 'income']);
    $income = app(RecordOneOffIncome::class)->handle(['received_on' => '2026-10-14', 'title' => 'Donativo', 'category_id' => $category->id, 'gross_amount' => 5, 'commission' => 0.68, 'commission_supplier_id' => Supplier::factory()->create()->id]);
    $line = BankTransaction::create(['hash' => 'w6', 'booked_on' => '2026-10-15', 'description' => 'STRIPE', 'amount' => 4.32, 'import_batch' => 'b']);

    Livewire::test(BankStatementImport::class)
        ->callTableAction('link', $line, data: ['targets' => ['payment:'.Payment::sole()->id, 'paid:'.$income->commission_expense_id]])
        ->assertHasNoTableActionErrors();

    expect($line->fresh()->difference())->toBe(0.0);
});

it('marks a line as own transfer', function () {
    $line = BankTransaction::create(['hash' => 'w2', 'booked_on' => '2026-07-10', 'description' => 'TRF DE PEDRO', 'amount' => 1500, 'import_batch' => 'b']);

    Livewire::test(BankStatementImport::class)->callTableAction('ownTransfer', $line);

    expect($line->fresh()->status)->toBe(BankTransactionStatus::OwnTransfer);
});

it('creates a one-off income from a credit line', function () {
    $line = BankTransaction::create(['hash' => 'w3', 'booked_on' => '2026-10-14', 'description' => 'STRIPE TECHNOLOGY EUROPE, LIMITED', 'amount' => 4.32, 'import_batch' => 'b']);

    Livewire::test(BankStatementImport::class)
        ->callTableAction('createIncome', $line, data: [
            'title' => 'Donativo Buy Me a Coffee',
            'gross_amount' => 5,
            'commission' => 0.68,
            'commission_supplier_id' => Supplier::factory()->create(['name' => 'Stripe'])->id,
            'method' => 'stripe',
        ])
        ->assertHasNoTableActionErrors();

    expect($line->fresh()->status)->toBe(BankTransactionStatus::Matched)
        ->and($line->fresh()->difference())->toBe(0.0)
        ->and(OneOffIncome::sole()->received_on->toDateString())->toBe('2026-10-14');
});

it('fills the commission from the gross amount so the income matches the bank', function () {
    $line = BankTransaction::create(['hash' => 'w5', 'booked_on' => '2026-10-14', 'description' => 'STRIPE', 'amount' => 4.32, 'import_batch' => 'b']);

    Livewire::test(BankStatementImport::class)
        ->mountTableAction('createIncome', $line)
        ->fillForm(['gross_amount' => 5])
        ->assertSchemaStateSet(['commission' => 0.68]);
});

it('puts a treated line back to pending', function () {
    $line = BankTransaction::create(['hash' => 'w4', 'booked_on' => '2026-07-10', 'description' => 'TRF', 'amount' => 10, 'import_batch' => 'b', 'status' => 'own_transfer']);

    Livewire::test(BankStatementImport::class)
        ->set('activeTab', 'done')
        ->callTableAction('undo', $line);

    expect($line->fresh()->status)->toBe(BankTransactionStatus::Pending);
});

it('uploads an Excel statement exported from the bank', function () {
    Livewire::test(BankStatementImport::class)
        ->callAction('upload', data: ['file' => UploadedFile::fake()->createWithContent('movimentos.xlsx', bankStatementWorkbook())])
        ->assertHasNoActionErrors()
        ->assertNotified('2 linhas novas do extrato');

    expect(BankTransaction::orderBy('booked_on')->pluck('amount')->all())->toEqual(['1500.00', '-151.00']);
});

it('asks for the columns when it cannot recognise the file', function () {
    $csv = "x;y;z\n05.10.2026;PAGAMENTO XPTO;-12,50\n";

    Livewire::test(BankStatementImport::class)
        ->callAction('upload', data: ['file' => UploadedFile::fake()->createWithContent('estranho.csv', $csv)])
        ->assertActionMounted('mapColumns')
        ->fillForm(['date' => 0, 'description' => 1, 'amount' => 2])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(BankTransaction::sole())->amount->toEqual('-12.50');
});

it('is reachable from the month close page', function () {
    Livewire::test(MonthClose::class)
        ->assertActionHasUrl('bankStatement', BankStatementImport::getUrl());
});
