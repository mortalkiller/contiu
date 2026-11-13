<?php

use App\Actions\ConfirmInvoiceImport;
use App\Actions\ImportExpenseDocuments;
use App\Actions\ReadImportedInvoice;
use App\Enums\ExpenseKind;
use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentType;
use App\Enums\InvoiceImportStatus;
use App\Filament\Pages\InvoiceImportInbox;
use App\Jobs\ReadInvoiceImport;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Client;
use App\Models\DirectInvoiceSale;
use App\Models\Expense;
use App\Models\FiscalDocument;
use App\Models\InvoiceImport;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\Supplier;
use App\Modules\InvoiceReader\InvoiceAiReader;
use App\Support\CurrentAccount;
use App\Support\ExpenseEvidence;
use App\Support\FiscalDocumentPdfs;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\AgentResponse;
use Livewire\Livewire;

use function Pest\Laravel\mock;

pest()->group('fiscal');

beforeEach(function () {
    config(['invoice-benchmark.enabled' => false, 'invoice-benchmark.live' => false]);
    Storage::fake('local');
    $this->travelTo('2026-11-13 10:00:00');
});

/** A photo of an invoice: its fiscal QR code, 20,00 € + 4,60 € of VAT from the supplier 508025338. */
function invoicePhoto(string $number = 'FT DEMO/7', string $issuer = '508025338'): UploadedFile
{
    $payload = "A:{$issuer}*B:999999990*C:PT*D:FT*E:N*F:20261110*G:{$number}*H:ABCD1234-7*I1:PT*I7:20.00*I8:4.60*N:4.60*O:24.60*Q:YhGV*R:9999";
    $png = (new QRCode(new QROptions(['outputType' => QROutputInterface::GDIMAGE_PNG, 'outputBase64' => false, 'scale' => 6])))->render($payload);

    return UploadedFile::fake()->createWithContent('fatura.png', $png);
}

/** What the AI would read on the page, beyond the QR code. */
function fakeInvoiceAi(array $fields): void
{
    config(['invoice-benchmark.enabled' => true, 'invoice-benchmark.live' => true]);
    $reader = mock(InvoiceAiReader::class);
    $reader->shouldReceive('extract')->andReturn(Mockery::mock(AgentResponse::class));
    $reader->shouldReceive('validatedData')->andReturn($fields);
}

function invoiceBankLine(int $amount, string $bookedOn): BankTransaction
{
    return BankTransaction::create([
        'hash' => Str::random(32), 'import_batch' => 'teste', 'description' => 'Pagamento Papelaria', 'booked_on' => $bookedOn, 'amount' => $amount,
    ]);
}

it('keeps each dropped invoice private to the account, queues its reading and takes the same file only once', function () {
    actingAsAdmin();
    Queue::fake([ReadInvoiceImport::class]);

    Livewire::test(InvoiceImportInbox::class)
        ->assertSee('Arrasta as faturas para aqui')
        ->assertSee('Importar o CSV do e-Fatura')
        ->assertDontSeeHtml('contiu-inv is-work')
        ->set('files', [invoicePhoto(), invoicePhoto('FT DEMO/8'), invoicePhoto()])
        ->assertHasNoErrors()
        ->assertSee('A ler…');

    $import = InvoiceImport::orderBy('id')->firstOrFail();
    expect(InvoiceImport::count())->toBe(2)
        ->and($import->status)->toBe(InvoiceImportStatus::Queued)
        ->and($import->storagePath())->toStartWith("invoice-imports/{$this->account->id}/")
        ->and(Storage::disk('local')->getVisibility($import->storagePath()))->toBe('private');
    Queue::assertPushed(ReadInvoiceImport::class, 2);
});

it('refuses a file that is neither a PDF nor a photo, and more than twenty files at a time', function () {
    actingAsAdmin();
    Queue::fake([ReadInvoiceImport::class]);

    Livewire::test(InvoiceImportInbox::class)
        ->set('files', [UploadedFile::fake()->createWithContent('notas.txt', 'texto')])
        ->assertHasErrors(['files'])
        ->assertSee('Escolhe um PDF ou uma fotografia JPEG, PNG ou WebP até 10 MB.')
        ->set('files', array_map(fn (int $index) => invoicePhoto("FT DEMO/{$index}"), range(1, 21)))
        ->assertSee('Escolhe até 20 faturas de cada vez.');

    expect(InvoiceImport::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('reads the amounts from the fiscal QR code alone and asks for the supplier it cannot name', function () {
    actingAsAdmin();

    Livewire::test(InvoiceImportInbox::class)->set('files', [invoicePhoto()])->assertHasNoErrors();

    $import = InvoiceImport::sole();
    expect($import->status)->toBe(InvoiceImportStatus::Read)
        ->and($import->data)->toMatchArray([
            'type' => 'invoice', 'number' => 'FT DEMO/7', 'date' => '2026-11-10', 'supplier_nif' => '508025338', 'supplier_name' => null,
            'net' => 2000, 'vat' => 460, 'total' => 2460, 'rates' => [['rate' => '0.2300', 'net' => 2000, 'vat' => 460]],
        ]);

    Livewire::test(InvoiceImportInbox::class)
        ->assertSee('Faltam dados')
        ->assertSee('Não conseguimos ler o fornecedor.')
        ->call('confirm')
        ->assertHasErrors(['current']);

    expect(Expense::count())->toBe(0);
});

it('cannot read a photo with no QR code while the AI is off, nor a document that is not an invoice', function () {
    actingAsAdmin();
    $blank = UploadedFile::fake()->image('sem-qr.png', 200, 200);

    Livewire::test(InvoiceImportInbox::class)->set('files', [$blank])->assertHasNoErrors();
    expect(InvoiceImport::sole())->status->toBe(InvoiceImportStatus::Unreadable)->error->toBe('no_qr');

    fakeInvoiceAi(['document_type' => 'Nota de crédito', 'gross_total' => '10.00', 'supplier_name' => 'Loja']);
    Livewire::test(InvoiceImportInbox::class)->set('files', [UploadedFile::fake()->image('nota.png', 210, 200)]);

    expect(InvoiceImport::latest('id')->first())->status->toBe(InvoiceImportStatus::Unreadable)->error->toBe('not_invoice');
    Livewire::test(InvoiceImportInbox::class)->call('open', InvoiceImport::latest('id')->first()->id)
        ->assertSee('Este documento não parece uma fatura');
});

it('fills in with the AI what the QR code does not carry, the QR code staying the truth for the amounts', function () {
    actingAsAdmin();
    fakeInvoiceAi([
        'document_type' => 'FT', 'supplier_name' => 'Papelaria Demo, Lda', 'supplier_nif' => '508025338', 'gross_total' => '99.99',
        'lines' => [['description' => 'Resma de papel A4', 'quantity' => '2', 'unit_price' => '10.00', 'vat_rate' => '23', 'line_total' => '20.00']],
    ]);

    Livewire::test(InvoiceImportInbox::class)->set('files', [invoicePhoto()])
        ->assertSee('Papelaria Demo, Lda')
        ->assertSee('fornecedor novo')
        ->assertSee('Vai ser uma despesa nova.');

    expect(InvoiceImport::sole()->data)->toMatchArray(['supplier_name' => 'Papelaria Demo, Lda', 'total' => 2460])
        ->lines->toHaveCount(1);
});

it('gives the whole screen to the workspace only, with a list that folds away and a panel that can be dragged', function () {
    actingAsAdmin();
    InvoiceImport::factory()->create();

    Livewire::test(InvoiceImportInbox::class)
        ->assertSeeHtml('contiu-inv is-work')
        ->assertSeeHtml('class="contiu-inv-collapse"')
        ->assertSeeHtml('role="separator"')
        ->call('dismiss')
        ->assertSee('Está tudo arrumado')
        ->assertDontSeeHtml('contiu-inv is-work');
});

it('saves a confirmed invoice as a new expense with its supplier, its supporting document and the received fiscal document', function () {
    actingAsAdmin();
    $category = Category::factory()->create(['type' => 'expense', 'name' => 'Material de escritório']);
    $import = InvoiceImport::factory()->create();

    Livewire::test(InvoiceImportInbox::class)
        ->assertSet('current', $import->id)
        ->call('setCategory', (string) $category->id)
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertSee('Está tudo arrumado')
        ->assertSee('1 despesa nova');

    $expense = Expense::sole();
    $supplier = Supplier::sole();
    expect($supplier)->name->toBe('Papelaria Demo, Lda')->vat_number->toBe('509876544')
        ->and($expense)->supplier_id->toBe($supplier->id)->category_id->toBe($category->id)
        ->net_amount->toBe(1000)->vat_amount->toBe(230)->invoice_number->toBe('FR 5512')
        ->is_paid->toBeTrue()->is_reviewed->toBeTrue()
        ->and($expense->spent_on->toDateString())->toBe('2026-11-03')
        ->and(Storage::disk('local')->exists(ExpenseEvidence::path($expense)))->toBeTrue();

    $document = FiscalDocument::sole();
    expect($document)->direction->toBe(FiscalDocumentDirection::Received)->type->toBe(FiscalDocumentType::InvoiceReceipt)
        ->number->toBe('FR 5512')->total_amount->toBe(1230)->party_tax_id->toBe('509876544')
        ->and($document->links->expense_id)->toBe($expense->id)
        ->and($import->refresh())->status->toBe(InvoiceImportStatus::Confirmed)->expense_id->toBe($expense->id)->fiscal_document_id->toBe($document->id);
});

it('keeps the photo of an invoice as a PDF supporting document', function () {
    actingAsAdmin();
    Supplier::factory()->create(['name' => 'Fornecedor QR', 'vat_number' => '508025338']);

    Livewire::test(InvoiceImportInbox::class)->set('files', [invoicePhoto()])->call('confirm')->assertHasNoErrors();

    $proof = Storage::disk('local')->get(ExpenseEvidence::path(Expense::sole()));
    expect(FiscalDocumentPdfs::isPdf($proof))->toBeTrue()
        ->and(FiscalDocument::sole()->currentPdf)->not->toBeNull();
});

it('joins the expense already typed for the same invoice instead of creating another', function () {
    actingAsAdmin();
    $supplier = Supplier::factory()->create(['name' => 'Papelaria Demo, Lda', 'vat_number' => '509876544']);
    $expense = Expense::factory()->unpaid()->create(['supplier_id' => $supplier->id, 'description' => 'Papel e canetas', 'spent_on' => '2026-11-03', 'net_amount' => 1000, 'vat_rate' => '0.23']);
    InvoiceImport::factory()->create();

    Livewire::test(InvoiceImportInbox::class)
        ->assertSee('Junta-se à despesa «Papel e canetas» que já tinhas lançado.')
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertSee('1 fatura juntada a uma despesa que já tinhas');

    expect(Expense::count())->toBe(1)
        ->and($expense->refresh())->invoice_number->toBe('FR 5512')->proof_path->not->toBeNull()
        ->and(FiscalDocument::sole()->links->expense_id)->toBe($expense->id);
});

it('asks whether a similar expense of the same supplier is this invoice, and follows the answer', function (string $answer, int $expenses, int $net) {
    actingAsAdmin();
    $supplier = Supplier::factory()->create(['name' => 'Papelaria Demo, Lda', 'vat_number' => '509876544']);
    $typed = Expense::factory()->create(['supplier_id' => $supplier->id, 'description' => 'Papelaria · novembro', 'spent_on' => '2026-11-05', 'net_amount' => 1050, 'vat_rate' => '0.23']);
    InvoiceImport::factory()->create();

    Livewire::test(InvoiceImportInbox::class)
        ->assertSee('É a despesa que já lançaste?')
        ->assertSee('Papelaria · novembro')
        ->call('confirm')
        ->assertHasErrors(['current'])
        ->call('answerExpense', $answer)
        ->call('confirm')
        ->assertHasNoErrors();

    expect(Expense::count())->toBe($expenses)
        ->and($typed->refresh()->net_amount)->toBe($net);
})->with([
    'yes: the expense takes the amount of the invoice' => ['yes', 1, 1000],
    'no: a new expense is created' => ['no', 2, 1050],
]);

it('creates one expense per VAT rate and records the invoice with its own totals', function () {
    actingAsAdmin();
    InvoiceImport::factory()->read([
        'type' => 'simplified_invoice', 'number' => 'FS 1/8812', 'net' => 2077, 'vat' => 268, 'total' => 2345,
        'rates' => [['rate' => '0.0600', 'net' => 1000, 'vat' => 60], ['rate' => '0.1300', 'net' => 400, 'vat' => 52], ['rate' => '0.2300', 'net' => 677, 'vat' => 156]],
    ])->create();
    $bank = invoiceBankLine(-2345, '2026-11-04');

    Livewire::test(InvoiceImportInbox::class)
        ->assertSee('Vão ser 3 despesas novas, uma por cada taxa de IVA.')
        ->assertSee('Movimento do banco encontrado')
        ->call('confirm')
        ->assertHasNoErrors();

    expect(Expense::orderBy('id')->get()->map(fn (Expense $expense) => [$expense->net_amount, $expense->vat_amount])->all())
        ->toBe([[1000, 60], [400, 52], [677, 156]])
        ->and(Expense::whereNull('proof_path')->count())->toBe(0)
        ->and(FiscalDocument::sole())->net_amount->toBe(2077)->vat_amount->toBe(268)->total_amount->toBe(2345)
        ->and(FiscalDocument::sole()->links)->toBeNull()
        ->and($bank->refresh()->links()->count())->toBe(3);
});

it('links the one bank line that paid the invoice to the new expense', function () {
    actingAsAdmin();
    InvoiceImport::factory()->read(['paid' => false, 'type' => 'invoice'])->create();
    $bank = invoiceBankLine(-1230, '2026-11-06');
    invoiceBankLine(-1230, '2026-10-01');

    Livewire::test(InvoiceImportInbox::class)->call('confirm')->assertHasNoErrors();

    expect(Expense::sole())->is_paid->toBeTrue()
        ->and(Expense::sole()->bankLink->bank_transaction_id)->toBe($bank->id);
});

it('does not import twice an invoice that is already among the received documents', function () {
    actingAsAdmin();
    app(ImportExpenseDocuments::class)->confirm('508025338', [[
        'document_date' => '2026-11-10', 'number' => 'FT DEMO/7', 'net_amount' => 2000, 'vat_amount' => 460, 'total_amount' => 2460,
        'atcud' => 'ABCD1234-7', 'party_tax_id' => '508025338', 'type' => 'invoice',
    ]], [['expense' => 'unlinked']]);

    Livewire::test(InvoiceImportInbox::class)->set('files', [invoicePhoto()])
        ->assertSee('Está tudo arrumado')
        ->assertSee('1 já estava importada');

    expect(InvoiceImport::sole()->status)->toBe(InvoiceImportStatus::Duplicate)
        ->and(FiscalDocument::count())->toBe(1)
        ->and(Expense::count())->toBe(0);
});

it('offers an invoice issued by the account itself as a sale, never as an expense', function () {
    actingAsAdmin();
    $this->account->update(['nif' => '509876544']);
    $client = Client::factory()->create(['name' => 'Estúdio Norte, Lda', 'vat_number' => '513456783']);
    $import = InvoiceImport::factory()->read([
        'type' => 'invoice', 'number' => 'FT 2026/14', 'date' => '2026-11-12', 'due_date' => '2026-12-12', 'atcud' => 'JF4K2P9Q-14',
        'customer_name' => 'Estúdio Norte, Lda', 'customer_nif' => '513456783', 'net' => 100000, 'vat' => 23000, 'total' => 123000,
        'rates' => [['rate' => '0.2300', 'net' => 100000, 'vat' => 23000]], 'paid' => false,
    ])->create();

    Livewire::test(InvoiceImportInbox::class)
        ->assertSee('Esta fatura foi passada por ti.')
        ->assertSee('Já é teu cliente')
        ->call('confirm')
        ->assertHasErrors(['current'])
        ->call('registerSale')
        ->assertHasNoErrors()
        ->assertSee('1 venda registada');

    $document = FiscalDocument::sole();
    $receivable = Receivable::sole();
    expect($document)->direction->toBe(FiscalDocumentDirection::Issued)->number->toBe('FT 2026/14')->client_id->toBe($client->id)->total_amount->toBe(123000)
        ->and($receivable)->amount->toBe(123000)
        ->and($receivable->due_on->toDateString())->toBe('2026-12-12')
        ->and(DirectInvoiceSale::sole()->receivable_id)->toBe($receivable->id)
        ->and($document->links->receivable_id)->toBe($receivable->id)
        ->and(Expense::count())->toBe(0)
        ->and($import->refresh()->status)->toBe(InvoiceImportStatus::Sale);
});

it('asks which total is right when the base and the VAT do not add up to it', function () {
    actingAsAdmin();
    $import = InvoiceImport::factory()->read(['net' => 1000, 'vat' => 230, 'total' => 1250])->create();

    Livewire::test(InvoiceImportInbox::class)
        ->assertSee('Qual é o total certo?')
        ->call('confirm')
        ->assertHasErrors(['current'])
        ->call('answerTotal', 'sum')
        ->assertDontSee('Qual é o total certo?')
        ->call('confirm')
        ->assertHasNoErrors();

    expect($import->refresh()->data['total'])->toBe(1230)
        ->and(Expense::sole()->total)->toBe(1230);
});

it('saves what was corrected before confirming, and says what is wrong in what was typed', function () {
    actingAsAdmin();
    $import = InvoiceImport::factory()->create();

    $page = Livewire::test(InvoiceImportInbox::class)
        ->call('correct')
        ->assertSet('form.supplier_name', 'Papelaria Demo, Lda')
        ->set('form.supplier_name', '')
        ->set('form.date', '2026-12-01')
        ->call('saveCorrection')
        ->assertHasErrors(['form.supplier_name', 'form.date'])
        ->assertSee('Indica o fornecedor.')
        ->assertSee('A data da fatura não pode ser no futuro.');

    expect(Expense::count())->toBe(0);

    $page->set('form.supplier_name', 'Papelaria do Bairro')
        ->set('form.date', '2026-11-04')
        ->set('form.description', 'Material para o escritório')
        ->set('form.rates.0.net', '20,00')
        ->set('form.rates.0.vat', '4,60')
        ->set('form.is_paid', false)
        ->call('saveCorrection')
        ->assertHasNoErrors();

    expect(Expense::sole())->description->toBe('Material para o escritório')->net_amount->toBe(2000)->vat_amount->toBe(460)->is_paid->toBeFalse()
        ->and(Expense::sole()->spent_on->toDateString())->toBe('2026-11-04')
        ->and(Supplier::sole()->name)->toBe('Papelaria do Bairro')
        ->and($import->refresh()->status)->toBe(InvoiceImportStatus::Confirmed);
});

it('edits the lines of the invoice and keeps them on the fiscal document when they add up to its base', function () {
    actingAsAdmin();
    $import = InvoiceImport::factory()->read(['lines' => [
        ['description' => 'Resma de papel A4', 'quantity' => '2', 'unit_price' => 325, 'rate' => '0.2300', 'total' => 650],
    ]])->create();

    Livewire::test(InvoiceImportInbox::class)
        ->call('editLines')
        ->assertSee('Linhas da fatura')
        ->assertSet('lines.0.unit_price', '3,25')
        ->assertSee('A fatura diz')
        ->call('addLine')
        ->call('saveLines')
        ->assertHasErrors(['lines.1.description', 'lines.1.unit_price'])
        ->assertSee('Descreve a linha, ou tira-a.')
        ->set('lines.1.description', 'Canetas gel (caixa de 10)')
        ->set('lines.1.quantity', '0,5')
        ->set('lines.1.unit_price', '7,00')
        ->assertSee('Bate com a fatura')
        ->call('saveLines')
        ->assertHasNoErrors()
        ->assertSet('mode', 'confirm')
        ->call('confirm')
        ->assertHasNoErrors();

    expect($import->refresh()->data['lines'][1])->toBe(['description' => 'Canetas gel (caixa de 10)', 'quantity' => '0.5', 'unit_price' => 700, 'rate' => '0.2300', 'total' => 350])
        ->and(FiscalDocument::sole()->lines->map(fn ($line) => [$line->description, $line->quantity, $line->unit_price, $line->vat_basis_points, $line->net_amount])->all())
        ->toBe([['Resma de papel A4', 2000, 325, 2300, 650], ['Canetas gel (caixa de 10)', 500, 700, 2300, 350]])
        ->and(FiscalDocument::sole())->net_amount->toBe(1000)->vat_amount->toBe(230);
});

it('keeps lines that do not add up to the invoice as a note only, off the fiscal document', function () {
    actingAsAdmin();
    InvoiceImport::factory()->read(['lines' => [
        ['description' => 'Resma de papel A4', 'quantity' => '2', 'unit_price' => 325, 'rate' => '0.2300', 'total' => 650],
    ]])->create();

    Livewire::test(InvoiceImportInbox::class)->call('confirm')->assertHasNoErrors();

    expect(FiscalDocument::sole()->lines)->toBeEmpty()
        ->and(Expense::sole()->description)->toBe('Resma de papel A4');
});

it('records a direct cost against the sale it belongs to, one of the account\'s own', function () {
    actingAsAdmin();
    $project = Project::factory()->create(['code' => 'OC-000123', 'title' => 'Identidade visual']);
    $foreign = CurrentAccount::run(Account::factory()->create(), fn () => Project::factory()->create());
    InvoiceImport::factory()->create();

    Livewire::test(InvoiceImportInbox::class)
        ->call('correct')
        ->assertSee('OC-000123 · Identidade visual')
        ->set('form.kind', 'direct')
        ->call('saveCorrection')
        ->assertHasErrors(['form.cost_centre'])
        ->assertSee('Escolhe a venda ou a avença a que este custo pertence.')
        ->set('form.cost_centre', 'project:'.$foreign->id)
        ->call('saveCorrection')
        ->assertHasErrors(['form.cost_centre'])
        ->set('form.cost_centre', 'project:'.$project->id)
        ->call('saveCorrection')
        ->assertHasNoErrors();

    expect(Expense::sole())->kind->toBe(ExpenseKind::Direct)->project_id->toBe($project->id)->retainer_id->toBeNull();
});

it('corrects the client and the due date of an invoice issued by the account before recording the sale', function () {
    actingAsAdmin();
    $this->account->update(['nif' => '509876544']);
    InvoiceImport::factory()->read([
        'type' => 'invoice', 'number' => 'FT 2026/14', 'date' => '2026-11-12', 'customer_name' => 'Estudio Nrte', 'customer_nif' => null,
        'net' => 100000, 'vat' => 23000, 'total' => 123000, 'rates' => [['rate' => '0.2300', 'net' => 100000, 'vat' => 23000]], 'paid' => false,
    ])->create();

    Livewire::test(InvoiceImportInbox::class)
        ->call('correct')
        ->assertSee('NIF do cliente')
        ->assertDontSee('Tipo de custo')
        ->set('form.supplier_name', 'Estúdio Norte, Lda')
        ->set('form.supplier_nif', '513456783')
        ->set('form.due_date', '2026-11-01')
        ->call('saveCorrection')
        ->assertHasErrors(['form.due_date'])
        ->set('form.due_date', '2026-12-12')
        ->call('saveCorrection')
        ->assertHasNoErrors();

    expect(Client::sole())->name->toBe('Estúdio Norte, Lda')->vat_number->toBe('513456783')
        ->and(FiscalDocument::sole())->direction->toBe(FiscalDocumentDirection::Issued)->client_id->toBe(Client::sole()->id)
        ->and(Receivable::sole()->due_on->toDateString())->toBe('2026-12-12')
        ->and(Expense::count())->toBe(0);
});

it('lets the person pick the expense the invoice belongs to and keep its amount', function () {
    actingAsAdmin();
    $supplier = Supplier::factory()->create(['name' => 'Papelaria Demo, Lda', 'vat_number' => '509876544']);
    $typed = Expense::factory()->create(['supplier_id' => $supplier->id, 'description' => 'Compras de outubro', 'spent_on' => '2026-10-01', 'net_amount' => 5000, 'vat_rate' => '0.23']);
    InvoiceImport::factory()->create();

    Livewire::test(InvoiceImportInbox::class)
        ->call('pickExpense')
        ->assertSee('A que despesa pertence?')
        ->assertSee('Compras de outubro')
        ->call('joinExpense')
        ->assertHasErrors(['picked'])
        ->set('picked', (string) $typed->id)
        ->call('joinExpense', false)
        ->assertSee('A despesa mantém os')
        ->call('confirm')
        ->assertHasNoErrors();

    expect(Expense::count())->toBe(1)
        ->and($typed->refresh())->net_amount->toBe(5000)->invoice_number->toBe('FR 5512')->proof_path->not->toBeNull()
        ->and(FiscalDocument::sole()->links)->toBeNull();
});

it('replaces an invoice that could not be read with the new photo taken of it', function () {
    actingAsAdmin();
    $unreadable = InvoiceImport::factory()->unreadable()->create(['mime' => 'image/png', 'filename' => 'desfocada.jpg']);

    Livewire::test(InvoiceImportInbox::class)
        ->call('open', $unreadable->id)
        ->call('replace')
        ->set('files', [invoicePhoto()])
        ->assertHasNoErrors();

    expect($unreadable->refresh()->status)->toBe(InvoiceImportStatus::Dismissed)
        ->and(InvoiceImport::where('status', InvoiceImportStatus::Read)->count())->toBe(1);
});

it('turns an invoice that could not be read into an expense from what is typed by hand', function () {
    actingAsAdmin();
    $import = InvoiceImport::factory()->unreadable()->create(['filename' => 'supermercado.jpg']);

    Livewire::test(InvoiceImportInbox::class)
        ->call('open', $import->id)
        ->assertSee('Não conseguimos ler esta fatura')
        ->call('fillByHand')
        ->set('form.total', '')
        ->call('saveByHand')
        ->assertHasErrors(['form.supplier_name', 'form.total'])
        ->set('form.supplier_name', 'Supermercado Demo')
        ->set('form.date', '2026-11-11')
        ->set('form.total', '31,20')
        ->set('form.rate', '0.2300')
        ->assertSee('25,37')
        ->assertSee('Com esta taxa o total dá')
        ->call('saveByHand')
        ->assertHasNoErrors();

    expect(Expense::sole())->net_amount->toBe(2537)->vat_amount->toBe(584)->description->toBe('Supermercado Demo')->proof_path->not->toBeNull()
        ->and(FiscalDocument::count())->toBe(0)
        ->and($import->refresh()->status)->toBe(InvoiceImportStatus::Confirmed);
});

it('goes on to the amounts of each rate when an invoice typed by hand has several', function () {
    actingAsAdmin();
    $import = InvoiceImport::factory()->unreadable()->create();

    Livewire::test(InvoiceImportInbox::class)
        ->call('open', $import->id)
        ->call('fillByHand')
        ->set('form.supplier_name', 'Supermercado Demo')
        ->set('form.date', '2026-11-11')
        ->set('form.total', '23,45')
        ->set('form.rate', 'several')
        ->call('saveByHand')
        ->assertHasNoErrors()
        ->assertSet('mode', 'correct')
        ->assertCount('form.rates', 2)
        ->assertSee('IVA por taxa');

    expect($import->refresh()->status)->toBe(InvoiceImportStatus::Read)
        ->and(Expense::count())->toBe(0);
});

it('prunes the invoices dealt with a month ago and the ones forgotten for three, with their files', function () {
    actingAsAdmin();
    $old = InvoiceImport::factory()->create(['status' => InvoiceImportStatus::Confirmed, 'handled_at' => now()->subDays(31)]);
    $recent = InvoiceImport::factory()->create(['status' => InvoiceImportStatus::Confirmed, 'handled_at' => now()->subDays(29)]);
    $waiting = InvoiceImport::factory()->create();
    $forgotten = InvoiceImport::factory()->create();
    InvoiceImport::whereKey($forgotten->id)->toBase()->update(['updated_at' => now()->subDays(91)]);

    $this->artisan('invoice-imports:prune')->assertSuccessful();

    expect(InvoiceImport::orderBy('id')->pluck('id')->all())->toBe([$recent->id, $waiting->id])
        ->and(Storage::disk('local')->exists($old->storagePath()))->toBeFalse()
        ->and(Storage::disk('local')->exists($forgotten->storagePath()))->toBeFalse()
        ->and(Storage::disk('local')->exists($recent->storagePath()))->toBeTrue();
});

it('leaves an invoice out without recording anything, and clears what is done to start again', function () {
    actingAsAdmin();
    $import = InvoiceImport::factory()->create();
    $path = $import->storagePath();

    Livewire::test(InvoiceImportInbox::class)
        ->call('dismiss')
        ->assertSee('1 ficou de fora')
        ->call('startOver')
        ->assertSee('Arrasta as faturas para aqui');

    expect(Expense::count())->toBe(0)
        ->and(InvoiceImport::count())->toBe(0)
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
});

it('forbids a read-only account from adding or confirming invoices', function () {
    actingAsAdmin();
    $import = InvoiceImport::factory()->create();
    $this->account->update(['trial_ends_at' => now()->subDay()]);
    $this->actingAs(ownerOf($this->account));

    expect(ConfirmInvoiceImport::canConfirm())->toBeFalse();

    Livewire::test(InvoiceImportInbox::class)
        ->assertDontSee('Está certo')
        ->call('confirm')
        ->assertForbidden();

    Livewire::test(InvoiceImportInbox::class)->set('files', [invoicePhoto()])->assertForbidden();

    expect($import->refresh()->status)->toBe(InvoiceImportStatus::Read)->and(Expense::count())->toBe(0);
});

it('shows the file of an invoice only inside its own account', function () {
    $owner = ownerOf($this->account);
    $import = InvoiceImport::factory()->create();
    $other = Account::factory()->create();
    $other->users()->attach($owner, ['role' => 'owner']);
    $url = fn (Account $account): string => route('filament.admin.pages.importar-faturas.ficheiro', ['tenant' => $account->slug, 'import' => $import->id]);

    asAWebRequest();
    $this->get($url($this->account))->assertRedirect();

    $this->actingAs($owner);
    asAWebRequest();
    $this->get($url($this->account))->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    asAWebRequest();
    $this->get($url($other))->assertNotFound();
});

it('reads a queued invoice only once, whatever happens to the job', function () {
    actingAsAdmin();
    Queue::fake([ReadInvoiceImport::class]);
    Livewire::test(InvoiceImportInbox::class)->set('files', [invoicePhoto()]);
    $import = InvoiceImport::sole();

    app(ReadImportedInvoice::class)->handle($import->id);
    $import->refresh()->update(['data' => [...$import->data, 'supplier_name' => 'Corrigido à mão']]);
    app(ReadImportedInvoice::class)->handle($import->id);

    expect($import->refresh())->status->toBe(InvoiceImportStatus::Read)
        ->and($import->data['supplier_name'])->toBe('Corrigido à mão');
});
