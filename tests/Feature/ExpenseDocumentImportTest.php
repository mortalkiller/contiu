<?php

use App\Actions\ApplyBankTransaction;
use App\Actions\ImportExpenseDocuments;
use App\Enums\BankTransactionStatus;
use App\Exceptions\AccountIsReadOnly;
use App\Filament\Pages\ExpenseDocumentImport;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Expense;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLink;
use App\Models\Supplier;
use App\Support\CurrentAccount;
use App\Support\ExpenseDocumentCsv;
use App\Support\ExpenseDocumentQr;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

pest()->group('fiscal');

function expenseDocumentCsv(string $lines = '2026-11-10;FT DEMO V2/2;20,00;4,60;24,60'): string
{
    return "data;referencia;liquido;iva;total\n".$lines;
}

function expenseDocumentCandidate(array $attributes = []): Expense
{
    return Expense::factory()->unpaid()->create($attributes + [
        'description' => 'Subscrição Demo V2',
        'spent_on' => '2026-11-10',
        'invoice_number' => 'FT DEMO V2/2',
        'net_amount' => 2000,
        'vat_rate' => '0.23',
    ]);
}

function expenseDocumentBankLine(array $attributes = []): BankTransaction
{
    return BankTransaction::create($attributes + [
        'hash' => Str::random(32),
        'import_batch' => 'teste',
        'description' => 'Pagamento Demo',
        'booked_on' => '2026-11-10',
        'amount' => -2460,
    ]);
}

function expenseDocumentQrPayload(): string
{
    return 'A:508025338*B:999999990*C:PT*D:FT*E:N*F:20261110*G:FT DEMO V2/2*H:ABCD1234-2*I1:PT*I7:20.00*I8:4.60*N:4.60*O:24.60*Q:YhGV*R:9999';
}

it('previews an invoice by QR and deduplicates a legacy CSV recorded under the supplier name', function () {
    actingAsAdmin();
    $csv = app(ExpenseDocumentCsv::class)->parse(expenseDocumentCsv());
    app(ImportExpenseDocuments::class)->confirm('Fornecedor QR', $csv, [['expense' => 'unlinked']]);
    Supplier::factory()->create(['name' => 'Fornecedor QR', 'vat_number' => 'PT508025338']);

    Livewire::test(ExpenseDocumentImport::class)
        ->set('method', 'qr')
        ->set('qrText', expenseDocumentQrPayload())
        ->call('previewQr')
        ->assertHasNoErrors()
        ->assertSee('Fornecedor QR')
        ->assertSee('ABCD1234-2')
        ->assertSee('Já importada');

    expect(FiscalDocument::count())->toBe(1);
})->group('ui');

it('reads a QR image and confirms the received invoice using the existing reconciliation flow', function () {
    actingAsAdmin();
    $supplier = Supplier::factory()->create(['name' => 'Fornecedor QR', 'vat_number' => '508025338']);
    $expense = expenseDocumentCandidate(['supplier_id' => $supplier->id]);
    $otherSupplier = Supplier::factory()->create(['vat_number' => '501234560']);
    expenseDocumentCandidate(['supplier_id' => $otherSupplier->id, 'description' => 'Despesa de outro fornecedor']);
    $bank = expenseDocumentBankLine();
    $photo = (new QRCode(new QROptions(['outputType' => QROutputInterface::GDIMAGE_PNG, 'outputBase64' => false])))
        ->render(expenseDocumentQrPayload());

    Livewire::test(ExpenseDocumentImport::class)
        ->set('method', 'qr')
        ->set('qrImage', UploadedFile::fake()->createWithContent('fatura.png', $photo))
        ->call('previewQrImage')
        ->assertHasNoErrors()
        ->assertSee('Pagamento Demo')
        ->assertDontSee('Despesa de outro fornecedor')
        ->set('choices.0.expense', (string) $expense->id)
        ->set('choices.0.bank', (string) $bank->id)
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertSee('Já importada');

    $document = FiscalDocument::sole();
    expect($document->atcud)->toBe('ABCD1234-2')
        ->and($document->party_tax_id)->toBe('508025338')
        ->and($document->party_name)->toBe('Fornecedor QR')
        ->and($document->links->expense_id)->toBe($expense->id)
        ->and($bank->fresh()->status)->toBe(BankTransactionStatus::Matched)
        ->and(app(ImportExpenseDocuments::class)->preview('PT508025338', app(ExpenseDocumentCsv::class)->parse(expenseDocumentCsv()))[0]['state'])->toBe('imported');
})->group('ui');

it('rejects unsupported or inconsistent fiscal QR codes', function (string $before, string $after) {
    expect(fn () => app(ExpenseDocumentQr::class)->parse(str_replace($before, $after, expenseDocumentQrPayload())))
        ->toThrow(DomainException::class);
})->with([
    'voided' => ['E:N', 'E:A'],
    'credit note' => ['D:FT', 'D:NC'],
    'unbalanced' => ['O:24.60', 'O:24.61'],
    'stamp duty' => ['*N:', '*M:1.00*N:'],
    'repeated total' => ['*O:', '*O:0.00*O:'],
])->group('integration');

it('parses UTF-8 CSV with a BOM and preserves Portuguese values in integer cents', function () {
    expect(app(ExpenseDocumentCsv::class)->parse("\xEF\xBB\xBF".expenseDocumentCsv('10/11/2026;"FT A;2";1 000,05;230,01;1 230,06')))
        ->toBe([[
            'document_date' => '2026-11-10',
            'number' => 'FT A;2',
            'net_amount' => 100005,
            'vat_amount' => 23001,
            'total_amount' => 123006,
        ]]);
})->group('integration');

it('rejects invalid CSV before producing any preview rows', function (string $csv) {
    expect(fn () => app(ExpenseDocumentCsv::class)->parse($csv))->toThrow(DomainException::class);
})->with([
    'wrong header' => 'date;reference;net;vat;total',
    'invalid date' => expenseDocumentCsv('31/02/2026;FT 1;20,00;4,60;24,60'),
    'inconsistent total' => expenseDocumentCsv('2026-11-10;FT 1;20,00;4,60;24,61'),
    'ambiguous number format' => expenseDocumentCsv('2026-11-10;FT 1;20,001;4,60;24,60'),
    'duplicate reference' => expenseDocumentCsv("2026-11-10;FT 1;20,00;4,60;24,60\n2026-11-10;FT 1;20,00;4,60;24,60"),
    'empty data' => expenseDocumentCsv(''),
    'invalid UTF-8' => expenseDocumentCsv("2026-11-10;FT \xFF;20,00;4,60;24,60"),
])->group('integration');

it('accepts zero-value invoice metadata without suggesting a bank debit', function () {
    expenseDocumentBankLine(['amount' => 0]);
    $rows = app(ExpenseDocumentCsv::class)->parse(expenseDocumentCsv('2026-11-10;FT ZERO;0;0;0'));

    expect($rows[0]['total_amount'])->toBe(0)
        ->and(app(ImportExpenseDocuments::class)->preview('123456789', $rows)[0]['banks'])->toBe([]);
})->group('integration');

it('previews scoped candidates and leaves an ambiguous match for the user to choose', function () {
    $first = expenseDocumentCandidate();
    $second = expenseDocumentCandidate();
    $debit = expenseDocumentBankLine();
    expenseDocumentBankLine(['amount' => 2460]);
    expenseDocumentBankLine(['booked_on' => '2026-12-11']);
    CurrentAccount::run(Account::factory()->create(), function () {
        expenseDocumentCandidate(['description' => 'Despesa de outra conta']);
        expenseDocumentBankLine(['description' => 'Movimento de outra conta']);
    });

    $row = app(ImportExpenseDocuments::class)
        ->preview('123456789', app(ExpenseDocumentCsv::class)->parse(expenseDocumentCsv()))[0];

    expect($row['expense_match'])->toBe('ambiguous')
        ->and(array_keys($row['expenses']))->toBe([$first->id, $second->id])
        ->and($row['bank_match'])->toBe('exact')
        ->and(array_keys($row['banks']))->toBe([$debit->id])
        ->and(FiscalDocument::count())->toBe(0)
        ->and($debit->fresh()->status)->toBe(BankTransactionStatus::Pending);
})->group('integration');

it('uploads a CSV, waits for explicit choices, and records the fiscal and bank links together', function () {
    actingAsAdmin();
    $expense = expenseDocumentCandidate();
    $bank = expenseDocumentBankLine();

    $page = Livewire::test(ExpenseDocumentImport::class)
        ->set('source', '123456789')
        ->set('file', UploadedFile::fake()->createWithContent('faturas.csv', expenseDocumentCsv()))
        ->call('preview')
        ->assertHasNoErrors()
        ->assertSee('FT DEMO V2/2')
        ->assertSee('Subscrição Demo V2')
        ->assertSee('Pagamento Demo')
        ->assertSet('choices', []);

    $page->call('confirm')->assertHasErrors('choices');
    expect(FiscalDocument::count())->toBe(0)->and($expense->fresh()->is_paid)->toBeFalse();

    $page->set('choices.0.expense', (string) $expense->id)
        ->set('choices.0.bank', (string) $bank->id)
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertSee('Já importada');

    $document = FiscalDocument::query()->sole();
    expect($document->number)->toBe('FT DEMO V2/2')
        ->and($document->direction->value)->toBe('received')
        ->and($document->type->value)->toBe('invoice')
        ->and($document->total_amount)->toBe(2460)
        ->and($document->links->expense_id)->toBe($expense->id)
        ->and($bank->fresh()->status)->toBe(BankTransactionStatus::Matched)
        ->and($expense->fresh()->bankLink->bank_transaction_id)->toBe($bank->id)
        ->and($expense->fresh()->is_paid)->toBeTrue()
        ->and(Expense::count())->toBe(1);
})->group('ui');

it('rolls back a whole confirmation when a bank candidate is no longer available', function () {
    $firstExpense = expenseDocumentCandidate();
    $secondExpense = expenseDocumentCandidate(['invoice_number' => 'FT 3']);
    $firstBank = expenseDocumentBankLine();
    $secondBank = expenseDocumentBankLine();
    $csv = expenseDocumentCsv("2026-11-10;FT DEMO V2/2;20,00;4,60;24,60\n2026-11-10;FT 3;20,00;4,60;24,60");
    $rows = app(ExpenseDocumentCsv::class)->parse($csv);
    $secondBank->update(['status' => BankTransactionStatus::Ignored]);

    expect(fn () => app(ImportExpenseDocuments::class)->confirm('123456789', $rows, [
        ['expense' => (string) $firstExpense->id, 'bank' => (string) $firstBank->id],
        ['expense' => (string) $secondExpense->id, 'bank' => (string) $secondBank->id],
    ]))->toThrow(DomainException::class);

    expect(FiscalDocument::count())->toBe(0)
        ->and($firstBank->fresh()->status)->toBe(BankTransactionStatus::Pending)
        ->and($firstExpense->fresh()->is_paid)->toBeFalse()
        ->and($firstExpense->bankLink()->exists())->toBeFalse();
})->group('integration');

it('deduplicates normalized source and reference per account and preserves an imported record', function () {
    $import = app(ImportExpenseDocuments::class);
    $parser = app(ExpenseDocumentCsv::class);
    $rows = $parser->parse(expenseDocumentCsv());
    $choices = [['expense' => 'unlinked']];

    expect($import->confirm('Fornecedor Demo', $rows, $choices))->toBe(1)
        ->and($import->confirm(' fornecedor demo ', $rows, $choices))->toBe(0);

    $changed = $parser->parse(expenseDocumentCsv('2026-11-10;FT DEMO V2/2;20,00;0,00;20,00'));
    expect(fn () => $import->confirm('Fornecedor Demo', $changed, $choices))->toThrow(DomainException::class)
        ->and(FiscalDocument::query()->sole()->total_amount)->toBe(2460)
        ->and($import->confirm('Outra origem', $rows, $choices))->toBe(1);

    CurrentAccount::run(Account::factory()->create(), function () use ($import, $rows, $choices) {
        expect($import->confirm('Fornecedor Demo', $rows, $choices))->toBe(1)
            ->and(FiscalDocument::count())->toBe(1);
    });

    expect(FiscalDocument::count())->toBe(2)->and(Expense::count())->toBe(0);
})->group('integration');

it('preserves a fiscal link already attached to an expense', function () {
    $expense = expenseDocumentCandidate();
    $document = FiscalDocument::factory()->create(['direction' => 'received', 'type' => 'invoice']);
    $link = $document->links()->create(['expense_id' => $expense->id]);
    $rows = app(ExpenseDocumentCsv::class)->parse(expenseDocumentCsv());

    expect(fn () => app(ImportExpenseDocuments::class)->confirm('123456789', $rows, [
        ['expense' => (string) $expense->id],
    ]))->toThrow(DomainException::class);

    expect(FiscalDocumentLink::query()->sole()->id)->toBe($link->id)
        ->and(FiscalDocument::count())->toBe(1);
})->group('integration');

it('rejects a selected expense or bank line from another account', function (string $target) {
    $expense = expenseDocumentCandidate();
    $bank = expenseDocumentBankLine();
    [$foreignExpense, $foreignBank] = CurrentAccount::run(Account::factory()->create(), fn () => [
        expenseDocumentCandidate(),
        expenseDocumentBankLine(),
    ]);
    $rows = app(ExpenseDocumentCsv::class)->parse(expenseDocumentCsv());

    expect(fn () => app(ImportExpenseDocuments::class)->confirm('123456789', $rows, [[
        'expense' => (string) ($target === 'expense' ? $foreignExpense->id : $expense->id),
        'bank' => (string) ($target === 'bank' ? $foreignBank->id : $bank->id),
    ]]))->toThrow(DomainException::class);

    expect(FiscalDocument::count())->toBe(0)
        ->and($expense->fresh()->is_paid)->toBeFalse()
        ->and($bank->fresh()->status)->toBe(BankTransactionStatus::Pending);
})->with(['expense', 'bank'])->group('integration');

it('permits read-only previews and refuses confirmation at the action boundary', function () {
    $this->account->update(['billing_exempt' => false, 'trial_ends_at' => now()->subDay()]);
    $this->actingAs(ownerOf($this->account));
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->account);
    $rows = app(ExpenseDocumentCsv::class)->parse(expenseDocumentCsv());

    expect(app(ImportExpenseDocuments::class)->preview('123456789', $rows))->toHaveCount(1)
        ->and(fn () => app(ImportExpenseDocuments::class)->confirm('123456789', $rows, [
            ['expense' => 'unlinked'],
        ]))->toThrow(AccountIsReadOnly::class)
        ->and(FiscalDocument::count())->toBe(0);
})->group('integration');

it('registers the expenses import route only for users who can access that tenant', function () {
    $owner = ownerOf($this->account);
    $other = Account::factory()->create();
    $url = route('filament.admin.pages.importar-faturas', ['tenant' => $this->account->slug]);
    $otherUrl = route('filament.admin.pages.importar-faturas', ['tenant' => $other->slug]);

    $this->actingAs($owner);
    asAWebRequest();
    $this->get($url)->assertOk()->assertSee('Ficheiro de faturas recebidas');
    asAWebRequest();
    $this->get($otherUrl)->assertNotFound();
})->group('ui');

it('requires a fresh preview after the user changes the source', function () {
    actingAsAdmin();

    Livewire::test(ExpenseDocumentImport::class)
        ->set('source', 'Origem inicial')
        ->set('file', UploadedFile::fake()->createWithContent('faturas.csv', expenseDocumentCsv()))
        ->call('preview')
        ->assertHasNoErrors()
        ->set('source', 'Outra origem')
        ->set('choices.0.expense', 'unlinked')
        ->call('confirm')
        ->assertHasErrors('file');

    expect(FiscalDocument::count())->toBe(0);
})->group('ui');

it('never replaces a bank link that the expense already has', function () {
    $expense = expenseDocumentCandidate();
    $oldBank = expenseDocumentBankLine();
    $newBank = expenseDocumentBankLine();
    app(ApplyBankTransaction::class)->linkRecords($oldBank, [$expense]);
    $rows = app(ExpenseDocumentCsv::class)->parse(expenseDocumentCsv());

    expect(fn () => app(ImportExpenseDocuments::class)->confirm('123456789', $rows, [
        ['expense' => (string) $expense->id, 'bank' => (string) $newBank->id],
    ]))->toThrow(DomainException::class);

    expect($expense->fresh()->bankLink->bank_transaction_id)->toBe($oldBank->id)
        ->and($oldBank->fresh()->status)->toBe(BankTransactionStatus::Matched)
        ->and($newBank->fresh()->status)->toBe(BankTransactionStatus::Pending)
        ->and(FiscalDocument::count())->toBe(0);
})->group('integration');

describe('Import preview summary', function () {
    beforeEach(function () {
        Http::preventStrayRequests();
        $this->travelTo(now()->setDate(2026, 11, 10));
    });

    it('counts the received invoices in the preview by how well they match an expense', function () {
        actingAsAdmin();
        Expense::factory()->unpaid()->create([
            'description' => 'Subscrição Demo', 'spent_on' => '2026-11-10', 'invoice_number' => 'FT DEMO/2',
            'net_amount' => 2000, 'vat_rate' => 0.23,
        ]);
        $csv = "data;referencia;liquido;iva;total\n2026-11-10;FT DEMO/2;20,00;4,60;24,60\n2026-11-09;FT DEMO/3;50,00;11,50;61,50";

        $page = Livewire::test(ExpenseDocumentImport::class)
            ->assertSee('Junta as faturas que recebeste às despesas, sem escrever os dados à mão.')
            ->assertDontSee('Faturas lidas')
            ->set('source', '123456789')
            ->set('file', UploadedFile::fake()->createWithContent('faturas.csv', $csv))
            ->call('preview')
            ->assertHasNoErrors();

        expect(importScreenText($page))->toContain(
            'Faturas lidas 2 Origem 123456789 Correspondência exata 1 Mesma data, valores e número Por confirmar 0 Nenhuma Sem candidatos 1 Cria a despesa a partir da fatura',
        );

        expect(FiscalDocument::query()->count())->toBe(0);
    })->group('ui');
});
