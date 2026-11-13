<?php

use App\Enums\ExpenseAttribution;
use App\Enums\ExpenseKind;
use App\Enums\FiscalDocumentDirection;
use App\Enums\PaymentMethod;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\Expenses\Pages\ViewExpense;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Client;
use App\Models\Expense;
use App\Models\FiscalDocument;
use App\Models\Project;
use App\Models\RecurringExpense;
use App\Models\Supplier;
use App\Models\User;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

pest()->group('ui');

/*
 * An expense's own page: what an unpaid and a paid expense show, what each is tied to, what the
 * bank card says, the one thing the page offers to do (marking it as paid) and who may open it.
 * Editing, the supporting document's upload and the list have their own tests.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-12 10:00'));
    actingAsAdmin();
});

/** The expense of the mock: internet from Loja Demo, 50,00 € plus VAT at 23%, still to pay. */
function unpaidInternetExpense(array $attributes = []): Expense
{
    return Expense::factory()
        ->for(Supplier::factory()->create(['name' => 'Loja Demo, S.A.', 'vat_number' => '509999966']))
        ->for(Category::factory()->create(['name' => 'Comunicações']))
        ->unpaid()
        ->create(['description' => 'Internet · novembro', 'spent_on' => '2026-11-10', 'net_amount' => 5000, 'vat_rate' => 0.23, ...$attributes]);
}

function withSupportingPdf(Expense $expense): Expense
{
    Storage::fake('local');
    $expense->proof_path = 'expense-proofs/'.$expense->account_id.'/'.Str::uuid().'.pdf';
    $expense->save();
    Storage::disk('local')->put($expense->proof_path, '%PDF-1.4');

    return $expense;
}

it('opens an expense\'s own page from its row in the list', function () {
    $expense = unpaidInternetExpense();

    Livewire::test(ListExpenses::class)
        ->loadTable()
        ->assertSeeHtml(ExpenseResource::getUrl('view', ['record' => $expense]));

    $this->get(ExpenseResource::getUrl('view', ['record' => $expense]))->assertOk();
});

it('shows an unpaid expense with its amounts, its missing supporting document and its supplier', function () {
    $expense = unpaidInternetExpense(['invoice_number' => 'FT A/2291', 'attribution' => ExpenseAttribution::Mixed, 'notes' => 'Internet do escritório em casa.']);

    Livewire::test(ViewExpense::class, ['record' => $expense->getRouteKey()])
        ->assertOk()
        ->assertSeeInOrder(['Internet · novembro', 'Por pagar', 'Estrutura', 'Loja Demo, S.A.', '10/11/2026'])
        ->assertSeeHtml(SupplierResource::getUrl('view', ['record' => $expense->supplier]))
        ->assertSeeInOrder(['Data', '10/11/2026', 'Categoria', 'Comunicações', 'Tipo', 'Estrutura', 'N.º da fatura', 'FT A/2291'])
        ->assertSeeInOrder(['Quanto custou', 'Taxa de IVA', '23%', 'Valor sem IVA', '50,00', 'IVA a 23%', '11,50', 'Total da despesa', '61,50', 'Afetação', 'Mista'])
        ->assertSeeInOrder(['Pagamento', 'Estado', 'Por pagar', 'Paga em', '—', 'Método', '—'])
        ->assertSeeInOrder(['Comprovativo', 'Ainda sem PDF.', 'Anexar PDF'])
        ->assertSeeHtml(ExpenseResource::getUrl('edit', ['record' => $expense]))
        ->assertSee('Internet do escritório em casa.')
        ->assertSeeInOrder(['Fornecedor', 'Loja Demo, S.A.', 'NIF 509999966', 'Portugal', 'Gasto em 2026', '1 despesa', '61,50'])
        ->assertSeeInOrder(['Revisão', 'Comprovativo', 'Em falta', 'Referência do comprovativo', '—', 'Revista', 'Ainda não'])
        ->assertDontSee('Ligado a')
        ->assertActionVisible('markPaid')
        ->assertActionHasUrl('editRecord', ExpenseResource::getUrl('edit', ['record' => $expense]))
        ->assertActionHidden('downloadProof');
});

it('shows a paid direct expense with its payment, its supporting document and what it is tied to', function () {
    $project = Project::factory()->for(Client::factory()->create(['name' => 'Estúdio Brisa']))->create(['code' => 'DEMO-05', 'title' => 'Loja online']);
    $recurring = RecurringExpense::factory()->create(['description' => 'Alojamento · mensal']);
    $expense = withSupportingPdf(Expense::factory()->for($project)->for($recurring)->create([
        'description' => 'Alojamento da loja', 'kind' => ExpenseKind::Direct, 'spent_on' => '2026-11-10', 'net_amount' => 10000, 'vat_rate' => 0.23,
        'paid_on' => '2026-11-11', 'method' => PaymentMethod::Card, 'proof_reference' => 'Arquivo 5', 'is_reviewed' => true,
    ]));
    $invoice = FiscalDocument::factory()->create([
        'direction' => FiscalDocumentDirection::Received, 'number' => 'FT A/2291', 'origin' => 'e-Fatura', 'document_date' => '2026-11-10',
        'net_amount' => 10000, 'vat_amount' => 2300, 'total_amount' => 12300,
    ]);
    $invoice->links()->create(['expense_id' => $expense->id]);
    // Another year's expense with the same supplier does not count as spent this year.
    Expense::factory()->for($expense->supplier)->create(['spent_on' => '2025-11-10', 'net_amount' => 99900]);

    Livewire::test(ViewExpense::class, ['record' => $expense->getRouteKey()])
        ->assertSeeInOrder(['Alojamento da loja', 'Paga', 'Direta'])
        ->assertSeeInOrder(['Pagamento', 'Estado', 'Paga', 'Paga em', '11/11/2026', 'Método', 'Cartão'])
        ->assertSeeInOrder(['Comprovativo PDF', 'Arquivo 5', 'Descarregar comprovativo'])
        ->assertSeeHtml(ExpenseResource::getUrl('download-proof', ['record' => $expense]))
        ->assertDontSee('Ainda sem PDF.')
        ->assertSeeInOrder(['Gasto em 2026', '1 despesa', '123,00'])
        ->assertSeeInOrder(['Ligado a', 'Loja online', 'Venda DEMO-05 · Estúdio Brisa', 'Alojamento · mensal', 'Despesa recorrente · gerada a 12/11/2026', 'FT A/2291', 'Fatura · recebida · e-Fatura', '123,00', 'Registad'])
        ->assertSeeHtml(ProjectResource::getUrl('view', ['record' => $project]))
        ->assertSeeHtml(FiscalDocumentResource::getUrl('view', ['record' => $invoice]))
        ->assertSeeInOrder(['Revisão', 'Guardado', 'Arquivo 5', 'Revista', 'Sim'])
        ->assertActionHidden('markPaid')
        ->assertActionVisible('downloadProof');
});

it('says in the bank card that an unpaid expense has no statement line yet', function () {
    $expense = unpaidInternetExpense();

    Livewire::test(ViewExpense::class, ['record' => $expense->getRouteKey()])
        ->assertSeeInOrder(['Banco', 'Sem movimento', 'Por pagar: ainda não há movimento do extrato para ligar.']);
})->group('banking');

it('shows in the bank card the statement line that explains a paid expense', function () {
    $expense = Expense::factory()->create(['description' => 'ChatGPT Pro', 'spent_on' => '2026-11-03', 'paid_on' => '2026-11-03', 'net_amount' => 8374]);
    $line = BankTransaction::create(['hash' => 'detail-1', 'booked_on' => '2026-11-04', 'description' => 'COMPRA OPENAI', 'amount' => -8374, 'balance' => 41626, 'status' => 'matched', 'import_batch' => 'b']);
    $expense->bankLink()->create(['bank_transaction_id' => $line->id]);

    Livewire::test(ViewExpense::class, ['record' => $expense->getRouteKey()])
        ->assertSeeInOrder(['Banco', 'Reconciliado', 'COMPRA OPENAI', '04/11/2026 · Ligado a esta linha', '83,74'])
        ->assertDontSee('Ainda não há nenhuma linha do extrato');
})->group('banking');

it('says a paid expense that no statement line covers is still to reconcile', function () {
    BankTransaction::create(['hash' => 'detail-2', 'booked_on' => '2026-11-01', 'description' => 'ABERTURA', 'amount' => 0, 'balance' => 50000, 'status' => 'matched', 'import_batch' => 'b']);
    $expense = Expense::factory()->create(['spent_on' => '2026-11-03', 'paid_on' => '2026-11-03', 'net_amount' => 8374]);

    Livewire::test(ViewExpense::class, ['record' => $expense->getRouteKey()])
        ->assertSeeInOrder(['Banco', 'Por reconciliar', 'Ainda não há nenhuma linha do extrato']);
})->group('banking');

it('leaves the bank card out without a bank account for the activity', function () {
    app(FinanceSettings::class)->set(['has_business_account' => false]);
    $expense = unpaidInternetExpense();

    Livewire::test(ViewExpense::class, ['record' => $expense->getRouteKey()])
        ->assertSee('Revisão')
        ->assertDontSee('Sem movimento');
})->group('banking');

it('marks an unpaid expense as paid today from its page', function () {
    $expense = unpaidInternetExpense();

    Livewire::test(ViewExpense::class, ['record' => $expense->getRouteKey()])
        ->callAction('markPaid')
        ->assertNotified('Despesa marcada como paga')
        ->assertRedirect(ExpenseResource::getUrl('view', ['record' => $expense]));

    Livewire::test(ViewExpense::class, ['record' => $expense->getRouteKey()])
        ->assertActionHidden('markPaid')
        ->assertDontSee('Por pagar')
        ->assertSeeInOrder(['Pagamento', 'Estado', 'Paga', 'Paga em', '12/11/2026']);
    expect($expense->fresh())
        ->is_paid->toBeTrue()
        ->paid_on->toDateString()->toBe('2026-11-12');
});

it('still marks an expense as paid from its row in the list', function () {
    $expense = unpaidInternetExpense();

    Livewire::test(ListExpenses::class)
        ->loadTable()
        ->callAction(TestAction::make('markPaid')->table($expense))
        ->assertNotified('Despesa marcada como paga');

    expect($expense->fresh()->is_paid)->toBeTrue();
});

it('does not let a read-only account mark an expense as paid', function () {
    $expense = unpaidInternetExpense();
    $this->actingAs(ownerOf($this->account));
    Filament::setTenant($this->account);
    $this->account->update(['trial_ends_at' => now()->subDay()]);

    Livewire::test(ViewExpense::class, ['record' => $expense->getRouteKey()])
        ->assertOk()
        ->assertActionDisabled('markPaid');

    expect($expense->fresh()->is_paid)->toBeFalse();
});

it('deletes the expense from its page and goes back to the list', function () {
    $expense = unpaidInternetExpense();

    Livewire::test(ViewExpense::class, ['record' => $expense->getRouteKey()])
        ->callAction('delete')
        ->assertRedirect(ExpenseResource::getUrl('index'));

    $this->assertModelMissing($expense);
});

it('does not find an expense of another account', function () {
    $foreign = CurrentAccount::run(Account::factory()->create(), fn (): Expense => Expense::factory()->create());

    $this->get(ExpenseResource::getUrl('view', ['record' => $foreign]))->assertNotFound();
})->group('security');

it('is closed to an accountant, who only has the review pages', function () {
    $expense = unpaidInternetExpense();
    $accountant = User::factory()->create();
    $this->account->users()->attach($accountant, ['role' => 'accountant']);
    $this->actingAs($accountant);
    asAWebRequest();

    $this->get(ExpenseResource::getUrl('view', ['record' => $expense], tenant: $this->account))->assertForbidden();
})->group('security');

it('is closed to a user who does not belong to the account', function () {
    $expense = unpaidInternetExpense();
    $this->actingAs(User::factory()->create());
    asAWebRequest();

    $this->get(ExpenseResource::getUrl('view', ['record' => $expense], tenant: $this->account))->assertNotFound();
})->group('security');

it('escapes what was typed in the description and the supplier\'s name', function () {
    $expense = Expense::factory()
        ->for(Supplier::factory()->create(['name' => '<b>Loja</b>']))
        ->create(['description' => '<script>alert(1)</script>']);

    Livewire::test(ViewExpense::class, ['record' => $expense->getRouteKey()])
        ->assertSee('<script>alert(1)</script>')
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->assertDontSeeHtml('<b>Loja</b>');
})->group('security');

it('opens with strict models, as they are in the local environment', function () {
    BankTransaction::create(['hash' => 'detail-3', 'booked_on' => '2026-11-04', 'description' => 'COMPRA', 'amount' => -1000, 'balance' => 0, 'status' => 'matched', 'import_batch' => 'b']);
    $project = Project::factory()->for(Client::factory())->create();
    $expense = withSupportingPdf(Expense::factory()->for($project)->for(RecurringExpense::factory())->create(['kind' => ExpenseKind::Direct]));
    $expense->bankLink()->create(['bank_transaction_id' => BankTransaction::sole()->id]);
    FiscalDocument::factory()->create(['direction' => FiscalDocumentDirection::Received])->links()->create(['expense_id' => $expense->id]);
    Model::shouldBeStrict();

    try {
        Livewire::test(ViewExpense::class, ['record' => $expense->getRouteKey()])
            ->assertOk()
            ->assertSee('Ligado a');
    } finally {
        Model::shouldBeStrict(false);
    }
});

it('loads the page with the same queries however many expenses and documents surround it', function () {
    $queries = function (Expense $expense): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(ViewExpense::class, ['record' => $expense->getRouteKey()])->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    $make = function (int $siblings, int $documents): Expense {
        $expense = Expense::factory()->for(Project::factory()->for(Client::factory()))->create(['kind' => ExpenseKind::Direct, 'spent_on' => '2026-11-03', 'paid_on' => '2026-11-03']);
        Expense::factory()->count($siblings)->for($expense->supplier)->create(['spent_on' => '2026-11-03', 'paid_on' => '2026-11-03']);

        foreach (range(1, $documents) as $index) {
            FiscalDocument::factory()->create(['direction' => FiscalDocumentDirection::Received])->links()->create(['expense_id' => $expense->id]);
        }

        return $expense;
    };
    $small = $make(1, 1);
    $large = $make(6, 4);

    // The first render also loads what is cached for the rest of the test (permissions, settings).
    $queries($small);

    expect($queries($large))->toBe($queries($small));
})->group('performance');
