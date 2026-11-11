<?php

use App\Actions\ApplyBankTransaction;
use App\Actions\ImportBankStatement;
use App\Actions\MatchBankTransactions;
use App\Enums\BankTransactionStatus;
use App\Enums\ExpenseKind;
use App\Enums\PaymentMethod;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\RecurringExpense;
use App\Models\Supplier;
use App\Support\FinanceSettings;
use Illuminate\Support\Carbon;

pest()->group('integration', 'banking');

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-11-06')));

it('stores statement lines as pending and skips them when the same statement is uploaded again', function () {
    $rows = [bankRow('2026-10-23', 'TRF GONCALO', 73000), bankRow('2026-10-27', 'OPENAI', -8374), bankRow('2026-10-27', 'OPENAI', -8374)];

    $first = app(ImportBankStatement::class)->store($rows);
    $again = app(ImportBankStatement::class)->store($rows);

    expect($first)->toBe(['created' => 3, 'skipped' => 0])
        ->and($again)->toBe(['created' => 0, 'skipped' => 3])
        ->and(BankTransaction::pending()->count())->toBe(3);
});

it('records a payment when a credit is accepted', function () {
    $receivable = receivableFor('Gonçalo Teixeira', 73000, '2026-10-31');
    $transaction = BankTransaction::create(['hash' => 'g', 'booked_on' => '2026-10-23', 'description' => 'TRF GONCALO TEIXEIRA', 'amount' => 73000, 'import_batch' => 'x']);

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
    $receivable = receivableFor('Cliente', 10000, '2026-10-31');
    $transaction = BankTransaction::create(['hash' => 'h', 'booked_on' => '2026-10-23', 'description' => 'TRF', 'amount' => 15000, 'import_batch' => 'x']);

    expect(fn () => app(ApplyBankTransaction::class)->toReceivable($transaction, $receivable))
        ->toThrow(DomainException::class);

    expect(Payment::count())->toBe(0)
        ->and($transaction->fresh()->status)->toBe(BankTransactionStatus::Pending);
});

it('marks the unpaid expense as paid on the bank date', function () {
    $expense = Expense::factory()->unpaid()->create(['net_amount' => 8374, 'spent_on' => '2026-10-27', 'method' => null]);
    $transaction = BankTransaction::create(['hash' => 'i', 'booked_on' => '2026-10-28', 'description' => 'OPENAI', 'amount' => -8374, 'import_batch' => 'x']);

    app(ApplyBankTransaction::class)->toExpense($transaction, $expense);

    expect($expense->fresh())
        ->is_paid->toBeTrue()
        ->paid_on->toDateString()->toBe('2026-10-28')
        ->method->toBe(PaymentMethod::Transfer)
        ->and($transaction->fresh()->status)->toBe(BankTransactionStatus::Matched)
        ->and($transaction->links()->sole()->marked_paid)->toBeTrue();
});

it('creates a paid expense from a recurring template', function () {
    $template = RecurringExpense::factory()->create(['net_amount' => 2233, 'vat_rate' => 0.23, 'kind' => ExpenseKind::Overhead, 'description' => 'Servidor']);
    $transaction = BankTransaction::create(['hash' => 'j', 'booked_on' => '2026-11-01', 'description' => 'DD OVH', 'amount' => -2747, 'import_batch' => 'x']);

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
    $transaction = BankTransaction::create(['hash' => 'k', 'booked_on' => '2026-10-06', 'description' => 'TRF INPI', 'amount' => -12300, 'import_batch' => 'x']);

    app(ApplyBankTransaction::class)->createExpense($transaction, [
        'supplier_id' => $supplier->id,
        'category_id' => $category->id,
        'description' => 'Registo de marca',
        'kind' => ExpenseKind::Overhead->value,
        'vat_rate' => '0.2300',
    ]);

    expect(Expense::sole())
        ->net_amount->toBe(10000)
        ->total->toBe(12300)
        ->is_paid->toBeTrue()
        ->and($transaction->fresh()->status)->toBe(BankTransactionStatus::Matched);
});

it('ignores a line', function () {
    $transaction = BankTransaction::create(['hash' => 'l', 'booked_on' => '2026-10-06', 'description' => 'TRANSF ENTRE CONTAS', 'amount' => -50000, 'import_batch' => 'x']);

    app(ApplyBankTransaction::class)->ignore($transaction);

    expect($transaction->fresh()->status)->toBe(BankTransactionStatus::Ignored);
});

it('links a line to a registered payment without recording it again', function () {
    $receivable = receivableFor('Gonçalo Teixeira', 73000, '2026-10-31');
    $payment = Payment::factory()->for($receivable)->create(['amount' => 73000, 'paid_on' => '2026-10-20']);
    $transaction = BankTransaction::create(['hash' => 'l1', 'booked_on' => '2026-10-23', 'description' => 'TRF', 'amount' => 73000, 'import_batch' => 'x']);

    app(ApplyBankTransaction::class)->linkRecords($transaction, [$payment]);

    expect(Payment::count())->toBe(1)
        ->and($payment->fresh()->paid_on->toDateString())->toBe('2026-10-20')
        ->and($transaction->fresh())->status->toBe(BankTransactionStatus::Matched)
        ->and($transaction->fresh()->records()->sole()->is($payment))->toBeTrue()
        ->and($transaction->links()->sole()->created_from_line)->toBeFalse();
});

it('links a debit to a paid expense even when the amounts differ', function () {
    $expense = Expense::factory()->create(['net_amount' => 15198, 'paid_on' => '2026-10-06']);
    $transaction = BankTransaction::create(['hash' => 'l2', 'booked_on' => '2026-10-06', 'description' => 'TRF', 'amount' => -15100, 'import_batch' => 'x']);

    app(ApplyBankTransaction::class)->linkRecords($transaction, [$expense]);

    expect($transaction->fresh()->records()->sole()->is($expense))->toBeTrue()
        ->and($expense->fresh()->total)->toBe(15198)
        ->and($transaction->fresh()->difference())->toBe(98)
        ->and($transaction->fresh()->difference_explanation)->toBeNull();
});

it('refuses to link a record twice or in the wrong direction', function () {
    $payment = Payment::factory()->create(['amount' => 5000]);
    $first = BankTransaction::create(['hash' => 'l3', 'booked_on' => '2026-11-01', 'description' => 'TRF', 'amount' => 5000, 'import_batch' => 'x']);
    $second = BankTransaction::create(['hash' => 'l4', 'booked_on' => '2026-11-01', 'description' => 'TRF', 'amount' => 5000, 'import_batch' => 'x']);
    $debit = BankTransaction::create(['hash' => 'l5', 'booked_on' => '2026-11-01', 'description' => 'TRF', 'amount' => -5000, 'import_batch' => 'x']);

    app(ApplyBankTransaction::class)->linkRecords($first, [$payment]);

    expect(fn () => app(ApplyBankTransaction::class)->linkRecords($second, [$payment]))->toThrow(DomainException::class, 'já está ligado')
        ->and(fn () => app(ApplyBankTransaction::class)->linkRecords($debit, [Payment::factory()->create()]))->toThrow(DomainException::class)
        ->and(fn () => app(ApplyBankTransaction::class)->linkRecords($second, [Expense::factory()->create()]))->toThrow(DomainException::class)
        ->and($second->fresh()->status)->toBe(BankTransactionStatus::Pending);
});

it('links one line to two payments and reports no difference', function () {
    $receivable = receivableFor('Gonçalo Teixeira', 200000, '2026-12-31');
    $first = Payment::factory()->for($receivable)->create(['amount' => 60000]);
    $second = Payment::factory()->for($receivable)->create(['amount' => 30000]);
    $transaction = BankTransaction::create(['hash' => 'm1', 'booked_on' => '2026-11-05', 'description' => 'TRF', 'amount' => 90000, 'import_batch' => 'x']);

    app(ApplyBankTransaction::class)->linkRecords($transaction, [$first, $second]);

    expect($transaction->fresh()->difference())->toBe(0)
        ->and($transaction->links()->count())->toBe(2)
        ->and($transaction->fresh()->status)->toBe(BankTransactionStatus::Matched);
});

it('nets a platform payout: income in, commission out', function () {
    $payment = Payment::factory()->create(['amount' => 500]);
    $commission = Expense::factory()->create(['net_amount' => 68]);
    $transaction = BankTransaction::create(['hash' => 'm2', 'booked_on' => '2026-10-14', 'description' => 'STRIPE', 'amount' => 432, 'import_batch' => 'x']);

    app(ApplyBankTransaction::class)->linkRecords($transaction, [$payment, $commission]);

    expect($transaction->fresh()->difference())->toBe(0);
});

it('marks an unpaid expense linked to a line as paid on the line date', function () {
    $expense = Expense::factory()->unpaid()->create(['net_amount' => 4000]);
    $transaction = BankTransaction::create(['hash' => 'm3', 'booked_on' => '2026-11-02', 'description' => 'DD', 'amount' => -4000, 'import_batch' => 'x']);

    app(ApplyBankTransaction::class)->linkRecords($transaction, [$expense]);

    expect($expense->fresh())->is_paid->toBeTrue()->paid_on->toDateString()->toBe('2026-11-02')
        ->and($transaction->links()->sole()->marked_paid)->toBeTrue();
});

it('marks a line as own transfer when the suggestion is accepted', function () {
    app(FinanceSettings::class)->set(['account_names' => 'Ordenado']);
    $transaction = BankTransaction::create(['hash' => 'o4', 'booked_on' => '2026-10-06', 'description' => 'TRF P/ Ordenado', 'amount' => -15100, 'import_batch' => 'x']);

    app(ApplyBankTransaction::class)->accept($transaction, app(MatchBankTransactions::class)->for($transaction)->best());

    expect($transaction->fresh()->status)->toBe(BankTransactionStatus::OwnTransfer)
        ->and($transaction->links()->count())->toBe(0);
});

it('does not overwrite a reconciliation from a stale bank line', function () {
    $transaction = BankTransaction::create([
        'hash' => 'stale-reconciliation', 'booked_on' => '2026-11-06', 'description' => 'Despesa',
        'amount' => -2460, 'import_batch' => 'teste',
    ]);
    $stale = $transaction->fresh();
    $expense = Expense::factory()->unpaid()->create(['net_amount' => 2000, 'vat_rate' => 0.23]);
    $other = Expense::factory()->unpaid()->create(['net_amount' => 2000, 'vat_rate' => 0.23]);
    $action = app(ApplyBankTransaction::class);

    $action->linkRecords($transaction, [$expense]);

    expect(fn () => $action->applyTargets($stale, ['expense:'.$other->id]))
        ->toThrow(DomainException::class, 'Esta linha do extrato já foi tratada.');
    expect(fn () => $action->ignore($stale))
        ->toThrow(DomainException::class, 'Esta linha do extrato já foi tratada.');
    expect($transaction->fresh()->status)->toBe(BankTransactionStatus::Matched)
        ->and($transaction->difference())->toBe(0)
        ->and($other->fresh()->is_paid)->toBeFalse();
});
