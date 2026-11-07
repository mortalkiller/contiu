<?php

use App\Actions\ApplyBankTransaction;
use App\Actions\ImportBankStatement;
use App\Actions\MatchBankTransactions;
use App\Enums\BankTransactionStatus;
use App\Enums\DifferenceExplanation;
use App\Enums\ExpenseKind;
use App\Enums\PaymentMethod;
use App\Models\BankTransaction;
use App\Models\BankTransactionLink;
use App\Models\Category;
use App\Models\Client;
use App\Models\Expense;
use App\Models\OneOffIncome;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\RecurringExpense;
use App\Models\Supplier;
use App\Models\User;
use App\Support\FinanceSettings;
use Illuminate\Support\Carbon;

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-11-06')));

function bankRow(string $date, string $description, float $amount): array
{
    return ['booked_on' => $date, 'description' => $description, 'amount' => $amount, 'balance' => null];
}

function receivableFor(string $clientName, float $amount, string $dueOn): Receivable
{
    $client = Client::factory()->create(['name' => $clientName]);
    $project = Project::factory()->for($client)->create(['net_amount' => $amount]);

    return Receivable::factory()->for($project, 'billable')->create(['amount' => $amount, 'due_on' => $dueOn]);
}

// Importar ----------------------------------------------------------------------------------

it('stores statement lines as pending and skips them when the same statement is uploaded again', function () {
    $rows = [bankRow('2026-10-23', 'TRF GONCALO', 730), bankRow('2026-10-27', 'OPENAI', -83.74), bankRow('2026-10-27', 'OPENAI', -83.74)];

    $first = app(ImportBankStatement::class)->store($rows);
    $again = app(ImportBankStatement::class)->store($rows);

    expect($first)->toBe(['created' => 3, 'skipped' => 0])
        ->and($again)->toBe(['created' => 0, 'skipped' => 3])
        ->and(BankTransaction::pending()->count())->toBe(3);
});

// Sugestões: créditos -----------------------------------------------------------------------

it('suggests the open receivable with the same amount and the client name in the description', function () {
    $right = receivableFor('Gonçalo Teixeira, Unipessoal Lda', 730, '2026-10-31');
    receivableFor('Mariana Sousa', 730, '2026-10-30');
    $transaction = BankTransaction::create(['hash' => 'a', 'booked_on' => '2026-10-23', 'description' => 'TRF GONCALO TEIXEIRA UNIPESSOAL', 'amount' => 730, 'import_batch' => 'x']);

    $suggestion = app(MatchBankTransactions::class)->for($transaction);

    expect($suggestion->best()?->model->is($right))->toBeTrue()
        ->and($suggestion->candidates)->toHaveCount(2);
});

it('makes no automatic suggestion when two open amounts tie, but lists both', function () {
    receivableFor('Cliente Alfa', 20, '2026-11-01');
    receivableFor('Cliente Beta', 20, '2026-11-01');
    $transaction = BankTransaction::create(['hash' => 'b', 'booked_on' => '2026-11-04', 'description' => 'MBWAY 912345678', 'amount' => 20, 'import_batch' => 'x']);

    $suggestion = app(MatchBankTransactions::class)->for($transaction);

    expect($suggestion->best())->toBeNull()
        ->and($suggestion->candidates)->toHaveCount(2);
});

it('suggests the only open amount that matches even without a name', function () {
    $only = receivableFor('Mariana Sousa', 20, '2026-11-01');
    receivableFor('Outro Cliente', 50, '2026-11-01');
    $transaction = BankTransaction::create(['hash' => 'c', 'booked_on' => '2026-11-04', 'description' => 'MBWAY 912345678', 'amount' => 20, 'import_batch' => 'x']);

    expect(app(MatchBankTransactions::class)->for($transaction)->best()?->model->is($only))->toBeTrue();
});

// Sugestões: débitos ------------------------------------------------------------------------

it('suggests the unpaid expense with the same total and the supplier in the description', function () {
    $openAi = Supplier::factory()->create(['name' => 'OpenAI']);
    $expense = Expense::factory()->unpaid()->for($openAi)->create(['net_amount' => 83.74, 'spent_on' => '2026-10-27']);
    $transaction = BankTransaction::create(['hash' => 'd', 'booked_on' => '2026-10-28', 'description' => 'COMPRA OPENAI *CHATGPT', 'amount' => -83.74, 'import_batch' => 'x']);

    $best = app(MatchBankTransactions::class)->for($transaction)->best();

    expect($best->type)->toBe('expense')
        ->and($best->model->is($expense))->toBeTrue();
});

it('suggests creating the expense from a recurring template when nothing is waiting to be paid', function () {
    $ovh = Supplier::factory()->create(['name' => 'OVH']);
    $template = RecurringExpense::factory()->for($ovh)->create(['net_amount' => 22.33, 'vat_rate' => 0.23]);
    $transaction = BankTransaction::create(['hash' => 'e', 'booked_on' => '2026-11-01', 'description' => 'DD OVH SAS', 'amount' => -27.47, 'import_batch' => 'x']);

    $best = app(MatchBankTransactions::class)->for($transaction)->best();

    expect($best->type)->toBe('template')
        ->and($best->model->is($template))->toBeTrue();
});

it('suggests a new expense for a known supplier when nothing else matches', function () {
    $inpi = Supplier::factory()->create(['name' => 'INPI']);
    $transaction = BankTransaction::create(['hash' => 'f', 'booked_on' => '2026-10-06', 'description' => 'TRF INPI TAXA', 'amount' => -151.98, 'import_batch' => 'x']);

    $best = app(MatchBankTransactions::class)->for($transaction)->best();

    expect($best->type)->toBe('supplier')
        ->and($best->model->is($inpi))->toBeTrue();
});

// Sugestões: já registados ------------------------------------------------------------------

it('suggests the payment already registered with the same amount a few days apart', function () {
    $receivable = receivableFor('Gonçalo Teixeira', 730, '2026-10-31');
    $payment = Payment::factory()->for($receivable)->create(['amount' => 730, 'paid_on' => '2026-10-20']);
    $transaction = BankTransaction::create(['hash' => 'r1', 'booked_on' => '2026-10-23', 'description' => 'TRF DE PEDRO MONTEIRO', 'amount' => 730, 'import_batch' => 'x']);

    $best = app(MatchBankTransactions::class)->for($transaction)->best();

    expect($best->type)->toBe('payment')
        ->and($best->model->is($payment))->toBeTrue()
        ->and($best->label())->toStartWith('Já registado · Recebimento');
});

it('does not suggest a registered payment that is far away or already linked to another line', function () {
    $receivable = receivableFor('Gonçalo Teixeira', 2000, '2026-12-31');
    Payment::factory()->for($receivable)->create(['amount' => 730, 'paid_on' => '2026-09-01']);
    $linked = Payment::factory()->for($receivable)->create(['amount' => 730, 'paid_on' => '2026-10-23']);
    BankTransaction::create(['hash' => 'r2', 'booked_on' => '2026-10-23', 'description' => 'TRF', 'amount' => 730, 'import_batch' => 'x', 'status' => 'matched'])
        ->links()->create(['linkable_type' => $linked->getMorphClass(), 'linkable_id' => $linked->id]);
    $transaction = BankTransaction::create(['hash' => 'r3', 'booked_on' => '2026-10-23', 'description' => 'TRF', 'amount' => 730, 'import_batch' => 'x']);

    expect(app(MatchBankTransactions::class)->for($transaction)->candidates)->toBeEmpty();
});

it('suggests the expense already registered as paid with the same total', function () {
    $inpi = Supplier::factory()->create(['name' => 'INPI']);
    $expense = Expense::factory()->for($inpi)->create(['net_amount' => 151.98, 'spent_on' => '2026-10-06', 'paid_on' => '2026-10-06']);
    $transaction = BankTransaction::create(['hash' => 'r4', 'booked_on' => '2026-10-07', 'description' => 'TRF INPI TAXA', 'amount' => -151.98, 'import_batch' => 'x']);

    $best = app(MatchBankTransactions::class)->for($transaction)->best();

    expect($best->type)->toBe('paid')
        ->and($best->model->is($expense))->toBeTrue()
        ->and($best->label())->toStartWith('Já registado · Despesa paga');
});

// Aplicar -----------------------------------------------------------------------------------

it('records a payment when a credit is accepted', function () {
    $receivable = receivableFor('Gonçalo Teixeira', 730, '2026-10-31');
    $transaction = BankTransaction::create(['hash' => 'g', 'booked_on' => '2026-10-23', 'description' => 'TRF GONCALO TEIXEIRA', 'amount' => 730, 'import_batch' => 'x']);

    app(ApplyBankTransaction::class)->accept($transaction, app(MatchBankTransactions::class)->for($transaction)->best());

    $payment = Payment::sole();

    expect($payment)
        ->receivable_id->toBe($receivable->id)
        ->paid_on->toDateString()->toBe('2026-10-23')
        ->method->toBe(PaymentMethod::Transfer)
        ->notes->toBe('Extrato bancário: TRF GONCALO TEIXEIRA')
        ->and($transaction->fresh()->status)->toBe(BankTransactionStatus::Matched)
        ->and($transaction->fresh()->records()->sole()->is($payment))->toBeTrue()
        ->and($transaction->links()->sole()->created_from_line)->toBeTrue();
});

it('refuses a credit larger than what is open on the chosen receivable', function () {
    $receivable = receivableFor('Cliente', 100, '2026-10-31');
    $transaction = BankTransaction::create(['hash' => 'h', 'booked_on' => '2026-10-23', 'description' => 'TRF', 'amount' => 150, 'import_batch' => 'x']);

    expect(fn () => app(ApplyBankTransaction::class)->toReceivable($transaction, $receivable))
        ->toThrow(DomainException::class);

    expect(Payment::count())->toBe(0)
        ->and($transaction->fresh()->status)->toBe(BankTransactionStatus::Pending);
});

it('marks the unpaid expense as paid on the bank date', function () {
    $expense = Expense::factory()->unpaid()->create(['net_amount' => 83.74, 'spent_on' => '2026-10-27', 'method' => null]);
    $transaction = BankTransaction::create(['hash' => 'i', 'booked_on' => '2026-10-28', 'description' => 'OPENAI', 'amount' => -83.74, 'import_batch' => 'x']);

    app(ApplyBankTransaction::class)->toExpense($transaction, $expense);

    expect($expense->fresh())
        ->is_paid->toBeTrue()
        ->paid_on->toDateString()->toBe('2026-10-28')
        ->method->toBe(PaymentMethod::Transfer)
        ->and($transaction->fresh()->status)->toBe(BankTransactionStatus::Matched)
        ->and($transaction->links()->sole()->marked_paid)->toBeTrue();
});

it('creates a paid expense from a recurring template', function () {
    $template = RecurringExpense::factory()->create(['net_amount' => 22.33, 'vat_rate' => 0.23, 'kind' => ExpenseKind::Overhead, 'description' => 'Servidor']);
    $transaction = BankTransaction::create(['hash' => 'j', 'booked_on' => '2026-11-01', 'description' => 'DD OVH', 'amount' => -27.47, 'import_batch' => 'x']);

    app(ApplyBankTransaction::class)->fromTemplate($transaction, $template);

    expect(Expense::sole())
        ->recurring_expense_id->toBe($template->id)
        ->description->toBe('Servidor')
        ->is_paid->toBeTrue()
        ->spent_on->toDateString()->toBe('2026-11-01')
        ->and($transaction->fresh()->status)->toBe(BankTransactionStatus::Matched)
        ->and($transaction->links()->sole()->created_from_line)->toBeTrue();
});

it('creates a new expense, splitting VAT out of the bank amount', function () {
    $supplier = Supplier::factory()->create();
    $category = Category::factory()->create();
    $transaction = BankTransaction::create(['hash' => 'k', 'booked_on' => '2026-10-06', 'description' => 'TRF INPI', 'amount' => -123, 'import_batch' => 'x']);

    app(ApplyBankTransaction::class)->createExpense($transaction, [
        'supplier_id' => $supplier->id,
        'category_id' => $category->id,
        'description' => 'Registo de marca',
        'kind' => ExpenseKind::Overhead->value,
        'vat_rate' => '0.2300',
    ]);

    expect(Expense::sole())
        ->net_amount->toEqual('100.00')
        ->total->toBe(123.0)
        ->is_paid->toBeTrue()
        ->and($transaction->fresh()->status)->toBe(BankTransactionStatus::Matched);
});

it('ignores a line', function () {
    $transaction = BankTransaction::create(['hash' => 'l', 'booked_on' => '2026-10-06', 'description' => 'TRANSF ENTRE CONTAS', 'amount' => -500, 'import_batch' => 'x']);

    app(ApplyBankTransaction::class)->ignore($transaction);

    expect($transaction->fresh()->status)->toBe(BankTransactionStatus::Ignored);
});

it('links a line to a registered payment without recording it again', function () {
    $receivable = receivableFor('Gonçalo Teixeira', 730, '2026-10-31');
    $payment = Payment::factory()->for($receivable)->create(['amount' => 730, 'paid_on' => '2026-10-20']);
    $transaction = BankTransaction::create(['hash' => 'l1', 'booked_on' => '2026-10-23', 'description' => 'TRF', 'amount' => 730, 'import_batch' => 'x']);

    app(ApplyBankTransaction::class)->linkRecords($transaction, [$payment]);

    expect(Payment::count())->toBe(1)
        ->and($payment->fresh()->paid_on->toDateString())->toBe('2026-10-20')
        ->and($transaction->fresh())->status->toBe(BankTransactionStatus::Matched)
        ->and($transaction->fresh()->records()->sole()->is($payment))->toBeTrue()
        ->and($transaction->links()->sole()->created_from_line)->toBeFalse();
});

it('links a debit to a paid expense even when the amounts differ', function () {
    $expense = Expense::factory()->create(['net_amount' => 151.98, 'paid_on' => '2026-10-06']);
    $transaction = BankTransaction::create(['hash' => 'l2', 'booked_on' => '2026-10-06', 'description' => 'TRF', 'amount' => -151.00, 'import_batch' => 'x']);

    app(ApplyBankTransaction::class)->linkRecords($transaction, [$expense], DifferenceExplanation::OtherAccount);

    expect($transaction->fresh()->records()->sole()->is($expense))->toBeTrue()
        ->and($expense->fresh()->total)->toBe(151.98)
        ->and($transaction->fresh()->difference())->toBe(0.98)
        ->and($transaction->fresh()->difference_explanation)->toBe(DifferenceExplanation::OtherAccount);
});

it('refuses to link a record twice or in the wrong direction', function () {
    $payment = Payment::factory()->create(['amount' => 50]);
    $first = BankTransaction::create(['hash' => 'l3', 'booked_on' => '2026-11-01', 'description' => 'TRF', 'amount' => 50, 'import_batch' => 'x']);
    $second = BankTransaction::create(['hash' => 'l4', 'booked_on' => '2026-11-01', 'description' => 'TRF', 'amount' => 50, 'import_batch' => 'x']);
    $debit = BankTransaction::create(['hash' => 'l5', 'booked_on' => '2026-11-01', 'description' => 'TRF', 'amount' => -50, 'import_batch' => 'x']);

    app(ApplyBankTransaction::class)->linkRecords($first, [$payment]);

    expect(fn () => app(ApplyBankTransaction::class)->linkRecords($second, [$payment]))->toThrow(DomainException::class, 'já está ligado')
        ->and(fn () => app(ApplyBankTransaction::class)->linkRecords($debit, [Payment::factory()->create()]))->toThrow(DomainException::class)
        ->and(fn () => app(ApplyBankTransaction::class)->linkRecords($second, [Expense::factory()->create()]))->toThrow(DomainException::class)
        ->and($second->fresh()->status)->toBe(BankTransactionStatus::Pending);
});

it('links one line to two payments and reports no difference', function () {
    $receivable = receivableFor('Gonçalo Teixeira', 2000, '2026-12-31');
    $first = Payment::factory()->for($receivable)->create(['amount' => 600]);
    $second = Payment::factory()->for($receivable)->create(['amount' => 300]);
    $transaction = BankTransaction::create(['hash' => 'm1', 'booked_on' => '2026-11-05', 'description' => 'TRF', 'amount' => 900, 'import_batch' => 'x']);

    app(ApplyBankTransaction::class)->linkRecords($transaction, [$first, $second]);

    expect($transaction->fresh()->difference())->toBe(0.0)
        ->and($transaction->links()->count())->toBe(2)
        ->and($transaction->fresh()->status)->toBe(BankTransactionStatus::Matched);
});

it('nets a platform payout: income in, commission out', function () {
    $payment = Payment::factory()->create(['amount' => 5]);
    $commission = Expense::factory()->create(['net_amount' => 0.68]);
    $transaction = BankTransaction::create(['hash' => 'm2', 'booked_on' => '2026-10-14', 'description' => 'STRIPE', 'amount' => 4.32, 'import_batch' => 'x']);

    app(ApplyBankTransaction::class)->linkRecords($transaction, [$payment, $commission]);

    expect($transaction->fresh()->difference())->toBe(0.0);
});

it('marks an unpaid expense linked to a line as paid on the line date', function () {
    $expense = Expense::factory()->unpaid()->create(['net_amount' => 40]);
    $transaction = BankTransaction::create(['hash' => 'm3', 'booked_on' => '2026-11-02', 'description' => 'DD', 'amount' => -40, 'import_batch' => 'x']);

    app(ApplyBankTransaction::class)->linkRecords($transaction, [$expense]);

    expect($expense->fresh())->is_paid->toBeTrue()->paid_on->toDateString()->toBe('2026-11-02')
        ->and($transaction->links()->sole()->marked_paid)->toBeTrue();
});

// Transferência minha, parcial, desfazer ----------------------------------------------------

it('suggests own transfer when the description has the user full name', function () {
    User::factory()->create(['name' => 'Pedro Monteiro']);
    $transaction = BankTransaction::create(['hash' => 'o1', 'booked_on' => '2026-07-10', 'description' => 'TRF DE PEDRO FILIPE PEREIRA MONTEIRO', 'amount' => 1500, 'import_batch' => 'x']);

    $best = app(MatchBankTransactions::class)->for($transaction)->best();

    expect($best->type)->toBe('own_transfer')
        ->and($best->label())->toBe('Transferência minha');
});

it('suggests own transfer for a salary transfer out', function () {
    $transaction = BankTransaction::create(['hash' => 'o2', 'booked_on' => '2026-10-06', 'description' => 'TRF P/ Ordenado', 'amount' => -151, 'import_batch' => 'x']);

    expect(app(MatchBankTransactions::class)->for($transaction)->best()?->type)->toBe('own_transfer');
});

it('does not suggest own transfer for a different person with one shared name', function () {
    User::factory()->create(['name' => 'Pedro Monteiro']);
    $transaction = BankTransaction::create(['hash' => 'o3', 'booked_on' => '2026-07-10', 'description' => 'TRF DE PEDRO SILVA', 'amount' => 100, 'import_batch' => 'x']);

    expect(app(MatchBankTransactions::class)->for($transaction)->candidates->pluck('type'))->not->toContain('own_transfer');
});

it('marks a line as own transfer when the suggestion is accepted', function () {
    $transaction = BankTransaction::create(['hash' => 'o4', 'booked_on' => '2026-10-06', 'description' => 'TRF P/ Ordenado', 'amount' => -151, 'import_batch' => 'x']);

    app(ApplyBankTransaction::class)->accept($transaction, app(MatchBankTransactions::class)->for($transaction)->best());

    expect($transaction->fresh()->status)->toBe(BankTransactionStatus::OwnTransfer)
        ->and($transaction->links()->count())->toBe(0);
});

it('suggests a partial receipt when the client name matches and the line is smaller', function () {
    $receivable = receivableFor('Gonçalo Teixeira', 1000, '2026-12-10');
    $transaction = BankTransaction::create(['hash' => 'p1', 'booked_on' => '2026-12-10', 'description' => 'TRF GONCALO TEIXEIRA', 'amount' => 400, 'import_batch' => 'x']);

    $best = app(MatchBankTransactions::class)->for($transaction)->best();

    expect($best->type)->toBe('partial')
        ->and($best->model->is($receivable))->toBeTrue()
        ->and($best->label())->toStartWith('Recebimento parcial');

    app(ApplyBankTransaction::class)->accept($transaction, $best);

    expect($receivable->fresh()->outstanding)->toBe(600.0);
});

it('does not suggest a partial receipt without the client name', function () {
    receivableFor('Gonçalo Teixeira', 1000, '2026-12-10');
    $transaction = BankTransaction::create(['hash' => 'p2', 'booked_on' => '2026-12-10', 'description' => 'MBWAY 912345678', 'amount' => 400, 'import_batch' => 'x']);

    expect(app(MatchBankTransactions::class)->for($transaction)->candidates)->toBeEmpty();
});

it('undo deletes a payment created from the line and keeps one that already existed', function () {
    $receivable = receivableFor('Gonçalo Teixeira', 1000, '2026-12-10');
    $existing = Payment::factory()->for($receivable)->create(['amount' => 300]);
    $created = BankTransaction::create(['hash' => 'u1', 'booked_on' => '2026-12-10', 'description' => 'TRF', 'amount' => 400, 'import_batch' => 'x']);
    $linked = BankTransaction::create(['hash' => 'u2', 'booked_on' => '2026-12-11', 'description' => 'TRF', 'amount' => 300, 'import_batch' => 'x']);
    app(ApplyBankTransaction::class)->toReceivable($created, $receivable);
    app(ApplyBankTransaction::class)->linkRecords($linked, [$existing]);

    app(ApplyBankTransaction::class)->undo($created);
    app(ApplyBankTransaction::class)->undo($linked);

    expect(Payment::pluck('id')->all())->toBe([$existing->id])
        ->and(BankTransaction::pending()->count())->toBe(2)
        ->and(BankTransactionLink::count())->toBe(0);
});

it('undo puts an expense marked paid by the line back to unpaid', function () {
    $expense = Expense::factory()->unpaid()->create(['net_amount' => 40]);
    $transaction = BankTransaction::create(['hash' => 'u3', 'booked_on' => '2026-11-02', 'description' => 'DD', 'amount' => -40, 'import_batch' => 'x']);
    app(ApplyBankTransaction::class)->toExpense($transaction, $expense);

    app(ApplyBankTransaction::class)->undo($transaction);

    expect($expense->fresh())->is_paid->toBeFalse()->paid_on->toBeNull();
});

it('undo returns an own transfer or an ignored line to pending', function () {
    $own = BankTransaction::create(['hash' => 'u4', 'booked_on' => '2026-11-02', 'description' => 'TRF', 'amount' => -40, 'import_batch' => 'x']);
    $ignored = BankTransaction::create(['hash' => 'u5', 'booked_on' => '2026-11-02', 'description' => 'X', 'amount' => -1, 'import_batch' => 'x']);
    app(ApplyBankTransaction::class)->markOwnTransfer($own);
    app(ApplyBankTransaction::class)->ignore($ignored);

    app(ApplyBankTransaction::class)->undo($own);
    app(ApplyBankTransaction::class)->undo($ignored);

    expect(BankTransaction::pending()->count())->toBe(2);
});

it('suggests own transfer for the account holder name set in the finance settings', function () {
    app(FinanceSettings::class)->set(['account_holder_name' => 'Pedro Filipe Pereira Monteiro']);
    $transaction = BankTransaction::create(['hash' => 'o5', 'booked_on' => '2026-07-10', 'description' => 'TRF DE PEDRO FILIPE PEREIRA MONTEIRO', 'amount' => 1500, 'import_batch' => 'x']);

    expect(app(MatchBankTransactions::class)->for($transaction)->best()?->type)->toBe('own_transfer');
});

// Revisão final -----------------------------------------------------------------------------

it('suggests the earliest open instalment for a partial receipt instead of a tie', function () {
    $first = receivableFor('Gonçalo Teixeira', 1000, '2026-11-30');
    Receivable::factory()->for($first->billable, 'billable')->create(['amount' => 1000, 'due_on' => '2027-01-31']);
    $transaction = BankTransaction::create(['hash' => 'f1', 'booked_on' => '2026-12-10', 'description' => 'TRF GONCALO TEIXEIRA', 'amount' => 400, 'import_batch' => 'x']);

    $best = app(MatchBankTransactions::class)->for($transaction)->best();

    expect($best?->type)->toBe('partial')
        ->and($best->model->is($first))->toBeTrue();
});

it('prefers the payment already registered over a partial receipt, so nothing is duplicated', function () {
    $receivable = receivableFor('Gonçalo Teixeira', 1000, '2026-12-31');
    $payment = Payment::factory()->for($receivable)->create(['amount' => 400, 'paid_on' => '2026-12-08']);
    $transaction = BankTransaction::create(['hash' => 'f2', 'booked_on' => '2026-12-10', 'description' => 'TRF GONCALO TEIXEIRA', 'amount' => 400, 'import_batch' => 'x']);

    $suggestion = app(MatchBankTransactions::class)->for($transaction);

    expect($suggestion->best()?->model->is($payment))->toBeTrue()
        ->and($suggestion->candidates->pluck('type'))->not->toContain('partial');
});

it('prefers the instalment with the exact amount over a partial of another one', function () {
    $first = receivableFor('Gonçalo Teixeira', 600, '2026-11-30');
    Receivable::factory()->for($first->billable, 'billable')->create(['amount' => 1400, 'due_on' => '2027-01-31']);
    $transaction = BankTransaction::create(['hash' => 'f3', 'booked_on' => '2026-12-10', 'description' => 'TRF GONCALO TEIXEIRA', 'amount' => 600, 'import_batch' => 'x']);

    $best = app(MatchBankTransactions::class)->for($transaction)->best();

    expect($best?->type)->toBe('receivable')
        ->and($best->model->is($first))->toBeTrue();
});

it('matches client names by whole words only', function () {
    receivableFor('Ana Costa', 1000, '2026-12-31');
    $transaction = BankTransaction::create(['hash' => 'f4', 'booked_on' => '2026-12-10', 'description' => 'TRF SANTANA LDA', 'amount' => 400, 'import_batch' => 'x']);

    expect(app(MatchBankTransactions::class)->for($transaction)->candidates)->toBeEmpty();
});

// Diferenças explicadas ---------------------------------------------------------------------

it('requires an explanation when the records do not add up', function () {
    $expense = Expense::factory()->create(['net_amount' => 151.98]);
    $transaction = BankTransaction::create(['hash' => 'x1', 'booked_on' => '2026-10-06', 'description' => 'TRF P/ Ordenado', 'amount' => -151, 'import_batch' => 'x']);

    expect(fn () => app(ApplyBankTransaction::class)->linkRecords($transaction, [$expense]))->toThrow(DomainException::class, 'diferença')
        ->and($transaction->fresh()->status)->toBe(BankTransactionStatus::Pending)
        ->and($transaction->links()->count())->toBe(0);
});

it('rejects an explanation that does not fit the difference', function () {
    $payment = Payment::factory()->create(['amount' => 1000]);
    $transaction = BankTransaction::create(['hash' => 'x2', 'booked_on' => '2026-11-02', 'description' => 'TRF', 'amount' => 1010, 'import_batch' => 'x']);

    expect(fn () => app(ApplyBankTransaction::class)->linkRecords($transaction, [$payment], DifferenceExplanation::BankFee))->toThrow(DomainException::class);
});

it('creates a bank fee expense when less arrived than was received', function () {
    $payment = Payment::factory()->create(['amount' => 1000]);
    $transaction = BankTransaction::create(['hash' => 'x3', 'booked_on' => '2026-11-02', 'description' => 'TRF CLIENTE', 'amount' => 995, 'import_batch' => 'x']);

    app(ApplyBankTransaction::class)->linkRecords($transaction, [$payment], DifferenceExplanation::BankFee);

    $fee = Expense::sole();

    expect($fee)->total->toBe(5.0)->is_paid->toBeTrue()
        ->and($fee->supplier->name)->toBe('Banco')
        ->and($fee->category->name)->toBe('Comissões')
        ->and($transaction->fresh()->difference())->toBe(0.0);
});

it('creates a bank fee expense when more left the account than the expense', function () {
    $expense = Expense::factory()->create(['net_amount' => 151.98]);
    $transaction = BankTransaction::create(['hash' => 'x4', 'booked_on' => '2026-10-06', 'description' => 'TRF', 'amount' => -160, 'import_batch' => 'x']);

    app(ApplyBankTransaction::class)->linkRecords($transaction, [$expense], DifferenceExplanation::BankFee);

    expect($transaction->fresh()->difference())->toBe(0.0)
        ->and(Expense::where('id', '!=', $expense->id)->sole()->total)->toBe(8.02);
});

it('records extra money on a credit as another income', function () {
    $payment = Payment::factory()->create(['amount' => 1000]);
    $transaction = BankTransaction::create(['hash' => 'x5', 'booked_on' => '2026-11-02', 'description' => 'TRF CLIENTE', 'amount' => 1010, 'import_batch' => 'x']);

    app(ApplyBankTransaction::class)->linkRecords($transaction, [$payment], DifferenceExplanation::OtherIncome);

    expect(OneOffIncome::sole()->net_amount)->toEqual('10.00')
        ->and($transaction->fresh()->difference())->toBe(0.0);
});

it('undo removes the fee expense and the explanation', function () {
    $payment = Payment::factory()->create(['amount' => 1000]);
    $transaction = BankTransaction::create(['hash' => 'x6', 'booked_on' => '2026-11-02', 'description' => 'TRF', 'amount' => 995, 'import_batch' => 'x']);
    app(ApplyBankTransaction::class)->linkRecords($transaction, [$payment], DifferenceExplanation::BankFee);

    app(ApplyBankTransaction::class)->undo($transaction);

    expect(Expense::count())->toBe(0)
        ->and(Payment::count())->toBe(1)
        ->and($transaction->fresh()->difference_explanation)->toBeNull();
});

it('nets a client payment and an expense paid from the personal account in one transfer', function () {
    $payment = Payment::factory()->create(['amount' => 730]);
    $chatGpt = Expense::factory()->create(['net_amount' => 83.74]);
    $transaction = BankTransaction::create(['hash' => 'x7', 'booked_on' => '2026-11-03', 'description' => 'TRF DE PEDRO', 'amount' => 646.26, 'import_batch' => 'x']);

    app(ApplyBankTransaction::class)->applyTargets($transaction, ['payment:'.$payment->id, 'paid:'.$chatGpt->id]);

    expect($transaction->fresh()->difference())->toBe(0.0)
        ->and($transaction->fresh()->status)->toBe(BankTransactionStatus::Matched);
});

it('offers only the explanations that fit each case', function () {
    expect(DifferenceExplanation::optionsFor(credit: true, lineExceeds: true))->toBe([DifferenceExplanation::OwnMoney, DifferenceExplanation::OtherIncome])
        ->and(DifferenceExplanation::optionsFor(credit: true, lineExceeds: false))->toBe([DifferenceExplanation::BankFee, DifferenceExplanation::OtherAccount, DifferenceExplanation::Adjustment])
        ->and(DifferenceExplanation::optionsFor(credit: false, lineExceeds: true))->toBe([DifferenceExplanation::BankFee, DifferenceExplanation::OwnMoney])
        ->and(DifferenceExplanation::optionsFor(credit: false, lineExceeds: false))->toBe([DifferenceExplanation::OtherAccount, DifferenceExplanation::Adjustment]);
});
