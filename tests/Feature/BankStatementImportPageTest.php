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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

pest()->group('ui', 'banking');

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-06'));
    Storage::fake('local');
    actingAsAdmin();
});

function statementCsv(): string
{
    return "Data mov.;Descrição;Débito;Crédito\n23-10-2026;TRF GONCALO TEIXEIRA;;730,00\n27-10-2026;COMPRA OPENAI CHATGPT;83,74;\n";
}

function openReceivableFor(string $name, int $amount): Receivable
{
    $project = Project::factory()->for(Client::factory()->create(['name' => $name]))->create(['net_amount' => $amount]);

    return Receivable::factory()->for($project, 'billable')->create(['amount' => $amount, 'due_on' => '2026-10-31']);
}

it('uploads a statement and lists its lines with suggestions', function () {
    openReceivableFor('Gonçalo Teixeira', 73000);

    Livewire::test(BankStatementImport::class)->loadTable()
        ->callAction('upload', data: ['file' => UploadedFile::fake()->createWithContent('extrato.csv', statementCsv())])
        ->assertHasNoActionErrors()
        ->assertNotified('2 linhas novas do extrato')
        ->assertCanSeeTableRecords(BankTransaction::all())
        ->assertSee('Recebimento')
        ->assertSee('Sem sugestão');

    expect(BankTransaction::pending()->count())->toBe(2);
});

it('accepts a suggestion with one click', function () {
    $receivable = openReceivableFor('Gonçalo Teixeira', 73000);
    $line = BankTransaction::create(['hash' => 'x1', 'booked_on' => '2026-10-23', 'description' => 'TRF GONCALO TEIXEIRA', 'amount' => 73000, 'import_batch' => 'b']);

    Livewire::test(BankStatementImport::class)->loadTable()
        ->callTableAction('accept', $line)
        ->assertNotified();

    expect($receivable->payments()->count())->toBe(1)
        ->and($line->fresh()->status)->toBe(BankTransactionStatus::Matched);
});

it('lets you choose among the possible matches', function () {
    openReceivableFor('Cliente Alfa', 2000);
    $beta = openReceivableFor('Cliente Beta', 2000);
    $line = BankTransaction::create(['hash' => 'x2', 'booked_on' => '2026-11-04', 'description' => 'MBWAY 912', 'amount' => 2000, 'import_batch' => 'b']);

    Livewire::test(BankStatementImport::class)->loadTable()
        ->assertTableActionHidden('accept', $line)
        ->callTableAction('link', $line, data: ['targets' => ['receivable:'.$beta->id]]);

    expect($beta->payments()->count())->toBe(1);
});

it('creates an expense from a debit and ignores another line', function () {
    $supplier = Supplier::factory()->create(['name' => 'OpenAI']);
    $debit = BankTransaction::create(['hash' => 'x3', 'booked_on' => '2026-10-27', 'description' => 'COMPRA OPENAI', 'amount' => -8374, 'import_batch' => 'b']);
    $transfer = BankTransaction::create(['hash' => 'x4', 'booked_on' => '2026-10-28', 'description' => 'TRF ENTRE CONTAS', 'amount' => -50000, 'import_batch' => 'b']);

    Livewire::test(BankStatementImport::class)->loadTable()
        ->callTableAction('createExpense', $debit, data: [
            'supplier_id' => $supplier->id,
            'description' => 'ChatGPT Pro',
            'kind' => 'overhead',
            'vat_rate' => '0.0000',
        ])
        ->assertHasNoTableActionErrors()
        ->callTableAction('ignore', $transfer);

    expect(Expense::sole())->description->toBe('ChatGPT Pro')->total->toBe(8374)
        ->and($transfer->fresh()->status)->toBe(BankTransactionStatus::Ignored);
});

it('accepts every unambiguous suggestion at once', function () {
    openReceivableFor('Gonçalo Teixeira', 73000);
    openReceivableFor('Mariana Sousa', 2000);
    $lines = collect([
        BankTransaction::create(['hash' => 'y1', 'booked_on' => '2026-10-23', 'description' => 'TRF GONCALO TEIXEIRA', 'amount' => 73000, 'import_batch' => 'b']),
        BankTransaction::create(['hash' => 'y2', 'booked_on' => '2026-11-04', 'description' => 'MBWAY MARIANA SOUSA', 'amount' => 2000, 'import_batch' => 'b']),
        BankTransaction::create(['hash' => 'y3', 'booked_on' => '2026-11-05', 'description' => 'TRF DESCONHECIDA', 'amount' => 99900, 'import_batch' => 'b']),
    ]);

    Livewire::test(BankStatementImport::class)->loadTable()
        ->callTableBulkAction('acceptSuggestions', $lines);

    expect(Payment::count())->toBe(2)
        ->and(BankTransaction::pending()->count())->toBe(1);
});

it('links a registered expense and retains the difference from the bank line', function () {
    $expense = Expense::factory()->for(Supplier::factory()->create(['name' => 'INPI']))->create(['net_amount' => 15198, 'spent_on' => '2026-10-06', 'paid_on' => '2026-10-06']);
    $line = BankTransaction::create(['hash' => 'z1', 'booked_on' => '2026-10-06', 'description' => 'TRF P/ Ordenado', 'amount' => -15100, 'import_batch' => 'b']);

    Livewire::test(BankStatementImport::class)->loadTable()
        ->mountTableAction('link', $line)
        ->fillForm(['targets' => ['paid:'.$expense->id]])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($line->fresh()->records()->sole()->is($expense))->toBeTrue()
        ->and($line->fresh()->difference())->toBe(98)
        ->and(Expense::count())->toBe(1);
});

it('links a line to two receivables of a client who paid both at once', function () {
    $first = openReceivableFor('Gonçalo Teixeira', 30000);
    $second = Receivable::factory()->for($first->billable, 'billable')->create(['amount' => 20000, 'due_on' => '2026-11-30']);
    $line = BankTransaction::create(['hash' => 'w1', 'booked_on' => '2026-11-04', 'description' => 'TRF GONCALO TEIXEIRA', 'amount' => 50000, 'import_batch' => 'b']);

    Livewire::test(BankStatementImport::class)->loadTable()
        ->callTableAction('link', $line, data: ['targets' => ['receivable:'.$first->id, 'receivable:'.$second->id]])
        ->assertHasNoTableActionErrors();

    expect($first->fresh()->outstanding)->toBe(0)
        ->and($second->fresh()->outstanding)->toBe(0)
        ->and($line->fresh()->difference())->toBe(0)
        ->and($line->links()->count())->toBe(2);
});

it('links a platform payout to a donation and its commission registered before', function () {
    $category = Category::factory()->create(['type' => 'income']);
    $income = app(RecordOneOffIncome::class)->handle(['received_on' => '2026-10-14', 'title' => 'Donativo', 'category_id' => $category->id, 'gross_amount' => 500, 'commission' => 68, 'commission_supplier_id' => Supplier::factory()->create()->id]);
    $line = BankTransaction::create(['hash' => 'w6', 'booked_on' => '2026-10-15', 'description' => 'STRIPE', 'amount' => 432, 'import_batch' => 'b']);

    Livewire::test(BankStatementImport::class)->loadTable()
        ->callTableAction('link', $line, data: ['targets' => ['payment:'.Payment::sole()->id, 'paid:'.$income->commission_expense_id]])
        ->assertHasNoTableActionErrors();

    expect($line->fresh()->difference())->toBe(0);
});

it('marks a line as own transfer', function () {
    $line = BankTransaction::create(['hash' => 'w2', 'booked_on' => '2026-07-10', 'description' => 'TRF DE PEDRO', 'amount' => 150000, 'import_batch' => 'b']);

    Livewire::test(BankStatementImport::class)->loadTable()->callTableAction('ownTransfer', $line);

    expect($line->fresh()->status)->toBe(BankTransactionStatus::OwnTransfer);
});

it('creates a one-off income from a credit line', function () {
    $line = BankTransaction::create(['hash' => 'w3', 'booked_on' => '2026-10-14', 'description' => 'STRIPE TECHNOLOGY EUROPE, LIMITED', 'amount' => 432, 'import_batch' => 'b']);

    Livewire::test(BankStatementImport::class)->loadTable()
        ->callTableAction('createIncome', $line, data: [
            'title' => 'Donativo Buy Me a Coffee',
            'gross_amount' => 5,
            'commission' => 0.68,
            'commission_supplier_id' => Supplier::factory()->create(['name' => 'Stripe'])->id,
            'method' => 'stripe',
        ])
        ->assertHasNoTableActionErrors();

    expect($line->fresh()->status)->toBe(BankTransactionStatus::Matched)
        ->and($line->fresh()->difference())->toBe(0)
        ->and(OneOffIncome::sole()->received_on->toDateString())->toBe('2026-10-14');
});

it('fills the commission from the gross amount so the income matches the bank', function () {
    $line = BankTransaction::create(['hash' => 'w5', 'booked_on' => '2026-10-14', 'description' => 'STRIPE', 'amount' => 432, 'import_batch' => 'b']);

    Livewire::test(BankStatementImport::class)->loadTable()
        ->mountTableAction('createIncome', $line)
        ->fillForm(['gross_amount' => 5])
        ->assertSchemaStateSet(['commission' => 0.68]);
});

it('puts a treated line back to pending', function () {
    $line = BankTransaction::create(['hash' => 'w4', 'booked_on' => '2026-07-10', 'description' => 'TRF', 'amount' => 1000, 'import_batch' => 'b', 'status' => 'own_transfer']);

    Livewire::test(BankStatementImport::class)->loadTable()
        ->set('activeTab', 'done')
        ->callTableAction('undo', $line);

    expect($line->fresh()->status)->toBe(BankTransactionStatus::Pending);
});

it('uploads an Excel statement exported from the bank', function (string $format) {
    Livewire::test(BankStatementImport::class)->loadTable()
        ->callAction('upload', data: ['file' => UploadedFile::fake()->createWithContent("movimentos.{$format}", bankStatementWorkbook(format: $format))])
        ->assertHasNoActionErrors()
        ->assertNotified('2 linhas novas do extrato');

    expect(BankTransaction::orderBy('booked_on')->pluck('amount')->all())->toBe([150000, -15100]);
})->with(['xlsx', 'xls']);

it('rejects a statement with too many columns and removes the upload without importing rows', function () {
    $contents = bankStatementWorkbook([
        ['10/07/2026', '09/07/2026', 'PAYMENT', '100.00', '100.00', 64 => 'extra column'],
    ]);

    Livewire::test(BankStatementImport::class)->loadTable()
        ->callAction('upload', data: ['file' => UploadedFile::fake()->createWithContent('movimentos.xlsx', $contents)])
        ->assertNotified('O extrato é demasiado grande ou complexo. Exporta um período mais curto e tenta novamente.');

    expect(BankTransaction::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('bank-statements'))->toBe([]);
});

it('does not start a second statement reader for the same account', function () {
    $lock = Cache::lock('bank-statement-import:'.$this->account->id, 120);
    expect($lock->get())->toBeTrue();

    try {
        Livewire::test(BankStatementImport::class)->loadTable()
            ->callAction('upload', data: ['file' => UploadedFile::fake()->createWithContent('extrato.csv', statementCsv())])
            ->assertNotified('Já está a ser lido um extrato para esta conta. Tenta novamente daqui a pouco.');

        expect(BankTransaction::count())->toBe(0)
            ->and(Storage::disk('local')->allFiles('bank-statements'))->toBe([]);
    } finally {
        $lock->release();
    }
});

it('asks for the columns when it cannot recognise the file', function () {
    $csv = "x;y;z\n05.10.2026;PAGAMENTO XPTO;-12,50\n";

    Livewire::test(BankStatementImport::class)->loadTable()
        ->callAction('upload', data: ['file' => UploadedFile::fake()->createWithContent('estranho.csv', $csv)])
        ->assertActionMounted('mapColumns')
        ->fillForm(['date' => 0, 'description' => 1, 'amount' => 2])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(BankTransaction::sole())->amount->toBe(-1250);
});

it('is reachable from the month close page while it has lines to process', function () {
    BankTransaction::create(['hash' => 'm1', 'booked_on' => '2026-11-04', 'description' => 'MBWAY 912', 'amount' => 2000, 'import_batch' => 'b']);

    Livewire::test(MonthClose::class)
        ->assertSeeInOrder(['3. Extrato', '1 linha por tratar'])
        ->assertSeeHtml('href="'.BankStatementImport::getUrl().'"');
});

it('refuses a mapping file set from the browser', function () {
    Livewire::test(BankStatementImport::class)->set('mappingFile', 'outra-conta/extrato.csv');
})->throws(CannotUpdateLockedPropertyException::class);

it('refuses a mapping headers list set from the browser', function () {
    Livewire::test(BankStatementImport::class)->set('mappingHeaders', ['x']);
})->throws(CannotUpdateLockedPropertyException::class);

it('keeps each account\'s uploads in its own folder', function () {
    Livewire::test(BankStatementImport::class)->loadTable()
        ->callAction('upload', data: ['file' => UploadedFile::fake()->createWithContent('estranho.csv', "x;y;z\n05.10.2026;PAGAMENTO XPTO;-12,50\n")])
        ->assertActionMounted('mapColumns')
        ->assertSet('mappingFile', fn (string $path) => str_starts_with($path, 'bank-statements/'.$this->account->id.'/'));
});

it('reads and deletes only files in the account\'s upload folder', function (string $path) {
    // The upload's state comes back from the browser: a forged one names a file already on the disk.
    Storage::disk('local')->put($path, statementCsv());

    Livewire::test(BankStatementImport::class)->loadTable()
        ->callAction('upload', data: ['file' => ['forjado' => $path]])
        ->assertHasActionErrors(['file']);

    expect(Storage::disk('local')->exists($path))->toBeTrue()
        ->and(BankTransaction::count())->toBe(0);
})->with([
    'another folder' => 'outra-pasta/extrato.csv',
    'another account' => 'bank-statements/999999/extrato.csv',
    'climbing out' => fn () => 'bank-statements/'.test()->account->id.'/../outra-pasta/extrato.csv',
]);

it('does not hand out a link to a file named from the browser', function () {
    // The file field asks the server for a preview link of each file in its state.
    $path = 'bank-statements/999999/extrato.csv';
    Storage::disk('local')->put($path, statementCsv());

    $page = Livewire::test(BankStatementImport::class)->loadTable()
        ->mountAction('upload')
        ->set('mountedActions.0.data.file', ['forjado' => $path]);

    expect($page->instance()->callSchemaComponentMethod('mountedActionSchema0.file', 'getUploadedFiles'))
        ->toBe(['forjado' => null]);
});
