<?php

use App\Actions\ManageCustomerAdvance;
use App\Actions\RecordAllocatedPayment;
use App\Actions\RecordOneOffIncome;
use App\Enums\FiscalDocumentType;
use App\Enums\PaymentMethod;
use App\Filament\Pages\BankStatementImport;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Filament\Resources\Payments\Pages\ViewPayment;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\DirectInvoiceSale;
use App\Models\FiscalDocument;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\User;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

pest()->group('ui');

/*
 * A payment received on its own page: what a plain payment, a split one and an advance show, the
 * fiscal document and the bank statement line beside them, what the page offers to do (the same
 * as the payment's row on the list) and who may open it. Recording, splitting and reconciling
 * payments have their own tests.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-20 10:00'));
    actingAsAdmin();

    $this->client = Client::factory()->create(['name' => 'Estúdio Brisa, Lda.', 'vat_number' => '509999982']);
    $this->client->structuredAddress()->create(['country_code' => 'PT', 'street' => 'Rua das Flores 12', 'postal_code' => '4000-001', 'locality' => 'Porto']);
    $this->project = Project::factory()->for($this->client)->create(['code' => 'DEMO-05', 'title' => 'Loja online']);
    $this->receivable = fn (int $amount, string $description, string $dueOn = '2026-11-12'): Receivable => Receivable::factory()
        ->for($this->project, 'billable')
        ->create(['amount' => $amount, 'description' => $description, 'due_on' => $dueOn]);
    $this->deposit = fn (array $attributes = []): Payment => Payment::factory()
        ->for(($this->receivable)(100000, 'sinal de 50%'))
        ->create(['amount' => 40000, 'paid_on' => '2026-11-12', 'method' => PaymentMethod::MbWay, ...$attributes]);
    $this->advance = fn (): Payment => app(ManageCustomerAdvance::class)->record($this->client->id, 20000, '2026-11-12', PaymentMethod::Transfer);
    $this->statementLine = fn (string $date, int $amount, string $status, string $description): BankTransaction => BankTransaction::create([
        'hash' => "payment-page-{$date}-{$amount}", 'booked_on' => $date, 'description' => $description,
        'amount' => $amount, 'balance' => $amount, 'status' => $status, 'import_batch' => 'b',
    ]);
});

it('opens a payment\'s own page from its row on the list', function () {
    $payment = ($this->deposit)();

    Livewire::test(ManagePayments::class)
        ->loadTable()
        ->assertSeeHtml(PaymentResource::getUrl('view', ['record' => $payment]));

    $this->get(PaymentResource::getUrl('view', ['record' => $payment]))->assertOk();
});

it('shows how much came in, who paid, when, how and what it pays', function () {
    $payment = ($this->deposit)(['invoice_number' => 'FR 2026/14', 'notes' => 'Sinal pago no dia da adjudicação.']);
    Payment::factory()->for(($this->receivable)(65000, 'acerto final'))->create(['amount' => 65000, 'paid_on' => '2026-03-02']);
    Payment::factory()->for(($this->receivable)(1000, 'do ano passado'))->create(['amount' => 1000, 'paid_on' => '2025-12-30']);

    Livewire::test(ViewPayment::class, ['record' => $payment->getRouteKey()])
        ->assertOk()
        ->assertSeeInOrder(['Recebimentos', '12/11/2026 · Estúdio Brisa, Lda.', 'Recebimento de 400,00', 'Recebido', 'Estúdio Brisa, Lda.', 'recebido a 12/11/2026', 'MB Way'])
        ->assertDontSee('Visualizar')
        ->assertSeeInOrder(['Recebido em', '12/11/2026', 'Como foi pago', 'MB Way', 'Valor', '400,00', 'N.º da fatura / recibo', 'FR 2026/14'])
        ->assertSeeInOrder(['O que este recebimento paga', 'Vencia a', 'Pago neste recebimento', 'Fica por receber'])
        ->assertSeeInOrder(['Loja online · sinal de 50%', 'Venda DEMO-05 · Estúdio Brisa, Lda.', '12/11/2026', Money::format(100000), '400,00', '600,00', 'Total recebido', '400,00'], false)
        ->assertSee('Sinal pago no dia da adjudicação.')
        ->assertSeeInOrder(['Cliente', 'Estúdio Brisa, Lda.', 'NIF 509999982 · Porto', 'Recebido em 2026', '2 recebimentos', Money::format(105000)], false)
        ->assertSeeHtml(ClientResource::getUrl('view', ['record' => $this->client]))
        ->assertSeeHtml(ProjectResource::getUrl('view', ['record' => $this->project]))
        ->assertSee('Ainda sem documento fiscal ligado a este recebimento.')
        ->assertSeeInOrder(['Impostos', 'Isento de impostos', 'Não', 'Conta para a estimativa de impostos'])
        ->assertDontSee('Sinal por afetar')
        ->assertActionVisible('edit')
        ->assertActionVisible('delete')
        ->assertActionHidden('applyCustomerAdvance');
});

it('lists every receivable a split payment pays', function () {
    $first = ($this->receivable)(30000, '1.ª prestação', '2026-10-01');
    $second = ($this->receivable)(50000, '2.ª prestação', '2026-11-01');
    $payment = app(RecordAllocatedPayment::class)->handle(
        ['amount' => 45000, 'paid_on' => '2026-11-12', 'method' => PaymentMethod::Transfer],
        [$first->id => 30000, $second->id => 15000],
    );

    Livewire::test(ViewPayment::class, ['record' => $payment->getRouteKey()])
        ->assertSeeInOrder(['Recebimento de 450,00', 'Estúdio Brisa, Lda.', 'Transferência'])
        ->assertSeeInOrder(['Loja online · 1.ª prestação', '01/10/2026', '300,00', '300,00', '0,00'])
        ->assertSeeInOrder(['Loja online · 2.ª prestação', '01/11/2026', '500,00', '150,00', '350,00', 'Total recebido', '450,00'])
        ->assertActionVisible('edit');
});

it('says that a payment left out of the tax estimates is exempt', function () {
    $payment = ($this->deposit)(['tax_exempt' => true]);

    Livewire::test(ViewPayment::class, ['record' => $payment->getRouteKey()])
        ->assertSeeInOrder(['Isento de impostos', 'Sim', 'Não conta para a estimativa de impostos'])
        ->assertSeeInOrder(['N.º da fatura / recibo', '—']);
});

it('says that an advance still waits for a charge and allocates it from the page', function () {
    $payment = ($this->advance)();
    $charge = ($this->receivable)(60000, 'adjudicação', '2026-11-30');

    Livewire::test(ViewPayment::class, ['record' => $payment->getRouteKey()])
        ->assertSeeInOrder(['Recebimento de 200,00', 'Recebido', 'Sinal por afetar', 'Estúdio Brisa, Lda.', 'recebido a 12/11/2026'])
        ->assertSee('Este sinal ainda não está afeto a nenhuma cobrança.')
        ->assertDontSee('Vencia a')
        ->assertActionHidden('edit')
        ->callAction('applyCustomerAdvance', ['receivable_id' => $charge->id, 'allocated_on' => '2026-11-15'])
        ->assertHasNoActionErrors()
        ->assertRedirect(PaymentResource::getUrl('view', ['record' => $payment]));

    expect($payment->allocations()->sole()->receivable_id)->toBe($charge->id);

    Livewire::test(ViewPayment::class, ['record' => $payment->getRouteKey()])
        ->assertSeeInOrder(['Recebimento de 200,00', 'Sinal'])
        ->assertSeeInOrder(['Loja online · adjudicação', '30/11/2026', '600,00', '200,00', '400,00'])
        ->assertDontSee('Sinal por afetar')
        ->assertActionHidden('applyCustomerAdvance')
        ->assertActionHidden('edit');
});

it('shows the fiscal documents of the payment and of what it pays, the payment\'s own first', function () {
    $payment = ($this->deposit)();
    $invoice = FiscalDocument::factory()->create(['type' => FiscalDocumentType::Invoice, 'number' => 'FT 2026/9', 'document_date' => '2026-11-01', 'total_amount' => 100000]);
    $invoice->links()->create(['receivable_id' => $payment->receivable_id]);
    $receipt = FiscalDocument::factory()->create(['type' => FiscalDocumentType::Receipt, 'number' => 'RC 2026/5', 'document_date' => '2026-11-12', 'total_amount' => 40000]);
    $receipt->links()->create(['payment_id' => $payment->id, 'related_fiscal_document_id' => $invoice->id]);
    FiscalDocument::factory()->create(['number' => 'FT 2026/99'])->links()->create(['payment_id' => ($this->deposit)()->id]);

    Livewire::test(ViewPayment::class, ['record' => $payment->getRouteKey()])
        ->assertSeeInOrder(['Documento fiscal', 'RC 2026/5', 'Recibo · emitido a 12/11/2026', '400,00', 'FT 2026/9', 'Fatura · emitida a 01/11/2026', Money::format(100000)], false)
        ->assertSeeHtml(FiscalDocumentResource::getUrl('view', ['record' => $receipt]))
        ->assertSeeHtml(FiscalDocumentResource::getUrl('view', ['record' => $invoice]))
        ->assertDontSee('FT 2026/99')
        ->assertDontSee('Ainda sem documento fiscal');
});

it('sends a payment the bank statement does not explain yet to the statement', function () {
    $payment = ($this->deposit)();

    Livewire::test(ViewPayment::class, ['record' => $payment->getRouteKey()])
        ->assertSeeInOrder(['Recebimento de 400,00', 'Recebido', 'Por reconciliar'])
        ->assertSeeInOrder(['Banco', 'Por reconciliar', 'Sem extrato importado', 'Ainda não há nenhuma linha do extrato que o cubra.'])
        ->assertActionVisible('reconcile')
        ->assertActionHasUrl('reconcile', BankStatementImport::getUrl());
})->group('banking');

it('shows the statement line that explains a reconciled payment and no longer asks to reconcile it', function () {
    ($this->statementLine)('2026-11-01', 0, 'matched', 'ABERTURA');
    $payment = ($this->deposit)();
    $payment->bankLink()->create(['bank_transaction_id' => ($this->statementLine)('2026-11-12', 40000, 'matched', 'MBWAY ESTUDIO BRISA')->id]);

    Livewire::test(ViewPayment::class, ['record' => $payment->getRouteKey()])
        ->assertSeeInOrder(['Recebimento de 400,00', 'Recebido', 'Reconciliado'])
        ->assertSeeInOrder(['Banco', 'Reconciliado', 'MBWAY ESTUDIO BRISA', '12/11/2026 · Ligado a esta linha', '400,00'])
        ->assertDontSee('Por reconciliar')
        ->assertActionHidden('reconcile');
})->group('banking');

it('leaves the bank out without a bank account for the activity', function () {
    app(FinanceSettings::class)->set(['has_business_account' => false]);
    $payment = ($this->deposit)();

    Livewire::test(ViewPayment::class, ['record' => $payment->getRouteKey()])
        ->assertSee('Recebimento de 400,00')
        ->assertDontSee('Por reconciliar')
        ->assertDontSee('Banco')
        ->assertActionHidden('reconcile');
})->group('banking');

it('edits the payment from its page with the form of the list', function () {
    $payment = ($this->deposit)();

    Livewire::test(ViewPayment::class, ['record' => $payment->getRouteKey()])
        ->callAction('edit', ['amount' => 450, 'notes' => 'Corrigido'])
        ->assertHasNoActionErrors()
        ->assertRedirect(PaymentResource::getUrl('view', ['record' => $payment]));

    expect($payment->refresh()->amount)->toBe(45000)
        ->and($payment->notes)->toBe('Corrigido')
        ->and($payment->allocations()->sole()->amount)->toBe(45000);
});

it('deletes the payment from its page and goes back to the list', function () {
    $payment = ($this->deposit)();

    Livewire::test(ViewPayment::class, ['record' => $payment->getRouteKey()])
        ->callAction('delete')
        ->assertRedirect(PaymentResource::getUrl('index'));

    $this->assertModelMissing($payment);
});

it('shows what happened to the payment, newest first', function () {
    config(['audit.console' => true]); // auditing is off for console runs, tests included
    $payment = ($this->deposit)();
    $this->travel(5)->minutes();
    $payment->update(['notes' => 'Corrigido']);

    Livewire::test(ViewPayment::class, ['record' => $payment->getRouteKey()])
        ->assertSeeInOrder(['Histórico', 'Recebimento alterado', '20/11/2026 às 10:05', 'Recebimento registado', '20/11/2026 às 10:00'])
        ->assertActionVisible('history');
});

it('offers no change while the account is read-only', function () {
    $payment = ($this->deposit)();
    $advance = ($this->advance)();
    $this->actingAs(ownerOf($this->account));
    $this->account->forceFill(['trial_ends_at' => now()->subDay()])->save();
    $this->account->unsetRelation('subscriptions');

    Livewire::test(ViewPayment::class, ['record' => $payment->getRouteKey()])
        ->assertOk()
        ->assertActionHidden('edit')
        ->assertActionHidden('delete');

    Livewire::test(ViewPayment::class, ['record' => $advance->getRouteKey()])
        ->assertOk()
        ->assertActionHidden('applyCustomerAdvance');
})->group('security');

it('does not find a payment of another account', function () {
    $foreign = CurrentAccount::run(Account::factory()->create(), fn (): Payment => Payment::factory()->create());

    $this->get(PaymentResource::getUrl('view', ['record' => $foreign]))->assertNotFound();
})->group('security');

it('is closed to an accountant, who only has the review pages', function () {
    $payment = ($this->deposit)();
    $accountant = User::factory()->create();
    $this->account->users()->attach($accountant, ['role' => 'accountant']);
    $this->actingAs($accountant);
    asAWebRequest();

    $this->get(PaymentResource::getUrl('view', ['record' => $payment], tenant: $this->account))->assertForbidden();
})->group('security');

it('is closed to a user who does not belong to the account', function () {
    $payment = ($this->deposit)();
    $this->actingAs(User::factory()->create());
    asAWebRequest();

    $this->get(PaymentResource::getUrl('view', ['record' => $payment], tenant: $this->account))->assertNotFound();
})->group('security');

it('escapes what was typed in the client\'s name, the receivable and the notes', function () {
    $this->client->update(['name' => '<b>Brisa</b>']);
    $payment = Payment::factory()
        ->for(($this->receivable)(40000, '<i>sinal</i>'))
        ->create(['amount' => 40000, 'notes' => '<script>alert(1)</script>']);

    Livewire::test(ViewPayment::class, ['record' => $payment->getRouteKey()])
        ->assertSee('<b>Brisa</b>')
        ->assertDontSeeHtml('<b>Brisa</b>')
        ->assertDontSeeHtml('<i>sinal</i>')
        ->assertDontSeeHtml('<script>alert(1)</script>');
})->group('security');

it('opens with strict models, as they are in the local environment', function () {
    ($this->statementLine)('2026-11-01', 0, 'matched', 'ABERTURA');
    $payment = ($this->deposit)(['invoice_number' => 'FR 2026/14']);
    $payment->bankLink()->create(['bank_transaction_id' => ($this->statementLine)('2026-11-12', 40000, 'matched', 'MBWAY')->id]);
    FiscalDocument::factory()->create(['type' => FiscalDocumentType::InvoiceReceipt, 'number' => 'FR 2026/14'])->links()->create(['payment_id' => $payment->id]);
    $advance = ($this->advance)();
    $donation = app(RecordOneOffIncome::class)->handle(['received_on' => '2026-11-14', 'title' => 'Donativo', 'gross_amount' => 500])
        ->receivables()->sole()->payments()->sole();
    $sale = DirectInvoiceSale::query()->create(['client_id' => $this->client->id, 'key' => 'payment-page', 'issued_on' => '2026-11-10', 'vat_rate' => 0]);
    $counter = Payment::factory()->for(Receivable::factory()->for($sale, 'billable')->create(['amount' => 3000, 'description' => 'Venda ao balcão']))->create(['amount' => 3000]);
    Model::shouldBeStrict();

    try {
        Livewire::test(ViewPayment::class, ['record' => $payment->getRouteKey()])->assertOk()->assertSee('FR 2026/14');
        Livewire::test(ViewPayment::class, ['record' => $advance->getRouteKey()])->assertOk()->assertSee('Sinal por afetar');
        Livewire::test(ViewPayment::class, ['record' => $donation->getRouteKey()])->assertOk()->assertSeeInOrder(['Donativo', 'Receita avulsa']);
        Livewire::test(ViewPayment::class, ['record' => $counter->getRouteKey()])->assertOk()->assertSeeInOrder(['Venda ao balcão', 'Estúdio Brisa, Lda.']);
    } finally {
        Model::shouldBeStrict(false);
    }
});

it('loads the page without a query per receivable or per document', function () {
    $queries = function (Payment $payment): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(ViewPayment::class, ['record' => $payment->getRouteKey()])->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    $split = function (int $receivables): Payment {
        $amounts = collect(range(1, $receivables))
            ->mapWithKeys(fn (int $index): array => [($this->receivable)(10000, "{$index}.ª prestação")->id => 10000]);
        $payment = app(RecordAllocatedPayment::class)->handle(
            ['amount' => 10000 * $receivables, 'paid_on' => '2026-11-12', 'method' => PaymentMethod::Transfer],
            $amounts->all(),
        );

        foreach ($amounts->keys() as $receivableId) {
            FiscalDocument::factory()->create()->links()->create(['receivable_id' => $receivableId]);
        }

        return $payment;
    };
    $small = $split(2);
    $large = $split(8);

    // The first render also loads what is cached for the rest of the test (permissions, settings).
    $queries($small);

    expect($queries($large))->toBe($queries($small));
})->group('performance');
