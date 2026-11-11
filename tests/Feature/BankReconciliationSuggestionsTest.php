<?php

use App\Actions\ApplyBankTransaction;
use App\Actions\MatchBankTransactions;
use App\Models\BankTransaction;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Receivable;
use App\Models\RecurringExpense;
use App\Models\Supplier;
use App\Models\User;
use App\Support\FinanceSettings;
use Illuminate\Support\Carbon;

pest()->group('integration', 'banking');

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-11-06')));

it('suggests the open receivable with the same amount and the client name in the description', function () {
    $right = receivableFor('Gonçalo Teixeira, Unipessoal Lda', 73000, '2026-10-31');
    receivableFor('Mariana Sousa', 73000, '2026-10-30');
    $transaction = BankTransaction::create(['hash' => 'a', 'booked_on' => '2026-10-23', 'description' => 'TRF GONCALO TEIXEIRA UNIPESSOAL', 'amount' => 73000, 'import_batch' => 'x']);

    $suggestion = app(MatchBankTransactions::class)->for($transaction);

    expect($suggestion->best()?->model->is($right))->toBeTrue()
        ->and($suggestion->candidates)->toHaveCount(2);
});

it('makes no automatic suggestion when two open amounts tie, but lists both', function () {
    receivableFor('Cliente Alfa', 2000, '2026-11-01');
    receivableFor('Cliente Beta', 2000, '2026-11-01');
    $transaction = BankTransaction::create(['hash' => 'b', 'booked_on' => '2026-11-04', 'description' => 'MBWAY 912345678', 'amount' => 2000, 'import_batch' => 'x']);

    $suggestion = app(MatchBankTransactions::class)->for($transaction);

    expect($suggestion->best())->toBeNull()
        ->and($suggestion->candidates)->toHaveCount(2);
});

it('suggests the only open amount that matches even without a name', function () {
    $only = receivableFor('Mariana Sousa', 2000, '2026-11-01');
    receivableFor('Outro Cliente', 5000, '2026-11-01');
    $transaction = BankTransaction::create(['hash' => 'c', 'booked_on' => '2026-11-04', 'description' => 'MBWAY 912345678', 'amount' => 2000, 'import_batch' => 'x']);

    expect(app(MatchBankTransactions::class)->for($transaction)->best()?->model->is($only))->toBeTrue();
});

it('suggests the unpaid expense with the same total and the supplier in the description', function () {
    $openAi = Supplier::factory()->create(['name' => 'OpenAI']);
    $expense = Expense::factory()->unpaid()->for($openAi)->create(['net_amount' => 8374, 'spent_on' => '2026-10-27']);
    $transaction = BankTransaction::create(['hash' => 'd', 'booked_on' => '2026-10-28', 'description' => 'COMPRA OPENAI *CHATGPT', 'amount' => -8374, 'import_batch' => 'x']);

    $best = app(MatchBankTransactions::class)->for($transaction)->best();

    expect($best->type)->toBe('expense')
        ->and($best->model->is($expense))->toBeTrue();
});

it('suggests creating the expense from a recurring template when nothing is waiting to be paid', function () {
    $ovh = Supplier::factory()->create(['name' => 'OVH']);
    $template = RecurringExpense::factory()->for($ovh)->create(['net_amount' => 2233, 'vat_rate' => 0.23]);
    $transaction = BankTransaction::create(['hash' => 'e', 'booked_on' => '2026-11-01', 'description' => 'DD OVH SAS', 'amount' => -2747, 'import_batch' => 'x']);

    $best = app(MatchBankTransactions::class)->for($transaction)->best();

    expect($best->type)->toBe('template')
        ->and($best->model->is($template))->toBeTrue();
});

it('suggests a new expense for a known supplier when nothing else matches', function () {
    $inpi = Supplier::factory()->create(['name' => 'INPI']);
    $transaction = BankTransaction::create(['hash' => 'f', 'booked_on' => '2026-10-06', 'description' => 'TRF INPI TAXA', 'amount' => -15198, 'import_batch' => 'x']);

    $best = app(MatchBankTransactions::class)->for($transaction)->best();

    expect($best->type)->toBe('supplier')
        ->and($best->model->is($inpi))->toBeTrue();
});

it('suggests the payment already registered with the same amount a few days apart', function () {
    $receivable = receivableFor('Gonçalo Teixeira', 73000, '2026-10-31');
    $payment = Payment::factory()->for($receivable)->create(['amount' => 73000, 'paid_on' => '2026-10-20']);
    $transaction = BankTransaction::create(['hash' => 'r1', 'booked_on' => '2026-10-23', 'description' => 'TRF DE PEDRO MONTEIRO', 'amount' => 73000, 'import_batch' => 'x']);

    $best = app(MatchBankTransactions::class)->for($transaction)->best();

    expect($best->type)->toBe('payment')
        ->and($best->model->is($payment))->toBeTrue()
        ->and($best->label())->toStartWith('Já registado · Recebimento');
});

it('does not suggest a registered payment that is far away or already linked to another line', function () {
    $receivable = receivableFor('Gonçalo Teixeira', 200000, '2026-12-31');
    Payment::factory()->for($receivable)->create(['amount' => 73000, 'paid_on' => '2026-09-01']);
    $linked = Payment::factory()->for($receivable)->create(['amount' => 73000, 'paid_on' => '2026-10-23']);
    BankTransaction::create(['hash' => 'r2', 'booked_on' => '2026-10-23', 'description' => 'TRF', 'amount' => 73000, 'import_batch' => 'x', 'status' => 'matched'])
        ->links()->create(['linkable_type' => $linked->getMorphClass(), 'linkable_id' => $linked->id]);
    $transaction = BankTransaction::create(['hash' => 'r3', 'booked_on' => '2026-10-23', 'description' => 'TRF', 'amount' => 73000, 'import_batch' => 'x']);

    expect(app(MatchBankTransactions::class)->for($transaction)->candidates)->toBeEmpty();
});

it('suggests the expense already registered as paid with the same total', function () {
    $inpi = Supplier::factory()->create(['name' => 'INPI']);
    $expense = Expense::factory()->for($inpi)->create(['net_amount' => 15198, 'spent_on' => '2026-10-06', 'paid_on' => '2026-10-06']);
    $transaction = BankTransaction::create(['hash' => 'r4', 'booked_on' => '2026-10-07', 'description' => 'TRF INPI TAXA', 'amount' => -15198, 'import_batch' => 'x']);

    $best = app(MatchBankTransactions::class)->for($transaction)->best();

    expect($best->type)->toBe('paid')
        ->and($best->model->is($expense))->toBeTrue()
        ->and($best->label())->toStartWith('Já registado · Despesa paga');
});

it('suggests own transfer when the description has the user full name', function () {
    $this->account->users()->attach(User::factory()->create(['name' => 'Pedro Monteiro']), ['role' => 'owner']);
    $transaction = BankTransaction::create(['hash' => 'o1', 'booked_on' => '2026-07-10', 'description' => 'TRF DE PEDRO FILIPE PEREIRA MONTEIRO', 'amount' => 150000, 'import_batch' => 'x']);

    $best = app(MatchBankTransactions::class)->for($transaction)->best();

    expect($best->type)->toBe('own_transfer')
        ->and($best->label())->toBe('Transferência minha');
});

it('suggests own transfer to an account named in the settings ("Ordenado" is the personal account)', function () {
    app(FinanceSettings::class)->set(['account_names' => "Pedro Filipe Pereira Monteiro\nOrdenado"]);
    $transaction = BankTransaction::create(['hash' => 'o2', 'booked_on' => '2026-10-06', 'description' => 'TRF P/ Ordenado', 'amount' => -15100, 'import_batch' => 'x']);

    expect(app(MatchBankTransactions::class)->for($transaction)->best()?->type)->toBe('own_transfer');
});

it('does not treat "ordenado" as special without the setting', function () {
    $transaction = BankTransaction::create(['hash' => 'o2b', 'booked_on' => '2026-10-06', 'description' => 'TRF P/ Ordenado', 'amount' => -15100, 'import_batch' => 'x']);

    expect(app(MatchBankTransactions::class)->for($transaction)->candidates->pluck('type'))->not->toContain('own_transfer');
});

it('does not suggest own transfer for a different person with one shared name', function () {
    $this->account->users()->attach(User::factory()->create(['name' => 'Pedro Monteiro']), ['role' => 'owner']);
    $transaction = BankTransaction::create(['hash' => 'o3', 'booked_on' => '2026-07-10', 'description' => 'TRF DE PEDRO SILVA', 'amount' => 10000, 'import_batch' => 'x']);

    expect(app(MatchBankTransactions::class)->for($transaction)->candidates->pluck('type'))->not->toContain('own_transfer');
});

it('suggests a partial receipt when the client name matches and the line is smaller', function () {
    $receivable = receivableFor('Gonçalo Teixeira', 100000, '2026-12-10');
    $transaction = BankTransaction::create(['hash' => 'p1', 'booked_on' => '2026-12-10', 'description' => 'TRF GONCALO TEIXEIRA', 'amount' => 40000, 'import_batch' => 'x']);

    $best = app(MatchBankTransactions::class)->for($transaction)->best();

    expect($best->type)->toBe('partial')
        ->and($best->model->is($receivable))->toBeTrue()
        ->and($best->label())->toStartWith('Recebimento parcial');

    app(ApplyBankTransaction::class)->accept($transaction, $best);

    expect($receivable->fresh()->outstanding)->toBe(60000);
});

it('does not suggest a partial receipt without the client name', function () {
    receivableFor('Gonçalo Teixeira', 100000, '2026-12-10');
    $transaction = BankTransaction::create(['hash' => 'p2', 'booked_on' => '2026-12-10', 'description' => 'MBWAY 912345678', 'amount' => 40000, 'import_batch' => 'x']);

    expect(app(MatchBankTransactions::class)->for($transaction)->candidates)->toBeEmpty();
});

it('suggests own transfer for the account holder name set in the finance settings', function () {
    app(FinanceSettings::class)->set(['account_names' => "Pedro Filipe Pereira Monteiro\nOrdenado"]);
    $transaction = BankTransaction::create(['hash' => 'o5', 'booked_on' => '2026-07-10', 'description' => 'TRF DE PEDRO FILIPE PEREIRA MONTEIRO', 'amount' => 150000, 'import_batch' => 'x']);

    expect(app(MatchBankTransactions::class)->for($transaction)->best()?->type)->toBe('own_transfer');
});

it('suggests the earliest open instalment for a partial receipt instead of a tie', function () {
    $first = receivableFor('Gonçalo Teixeira', 100000, '2026-11-30');
    Receivable::factory()->for($first->billable, 'billable')->create(['amount' => 100000, 'due_on' => '2027-01-31']);
    $transaction = BankTransaction::create(['hash' => 'f1', 'booked_on' => '2026-12-10', 'description' => 'TRF GONCALO TEIXEIRA', 'amount' => 40000, 'import_batch' => 'x']);

    $best = app(MatchBankTransactions::class)->for($transaction)->best();

    expect($best?->type)->toBe('partial')
        ->and($best->model->is($first))->toBeTrue();
});

it('prefers the payment already registered over a partial receipt, so nothing is duplicated', function () {
    $receivable = receivableFor('Gonçalo Teixeira', 100000, '2026-12-31');
    $payment = Payment::factory()->for($receivable)->create(['amount' => 40000, 'paid_on' => '2026-12-08']);
    $transaction = BankTransaction::create(['hash' => 'f2', 'booked_on' => '2026-12-10', 'description' => 'TRF GONCALO TEIXEIRA', 'amount' => 40000, 'import_batch' => 'x']);

    $suggestion = app(MatchBankTransactions::class)->for($transaction);

    expect($suggestion->best()?->model->is($payment))->toBeTrue()
        ->and($suggestion->candidates->pluck('type'))->not->toContain('partial');
});

it('prefers the instalment with the exact amount over a partial of another one', function () {
    $first = receivableFor('Gonçalo Teixeira', 60000, '2026-11-30');
    Receivable::factory()->for($first->billable, 'billable')->create(['amount' => 140000, 'due_on' => '2027-01-31']);
    $transaction = BankTransaction::create(['hash' => 'f3', 'booked_on' => '2026-12-10', 'description' => 'TRF GONCALO TEIXEIRA', 'amount' => 60000, 'import_batch' => 'x']);

    $best = app(MatchBankTransactions::class)->for($transaction)->best();

    expect($best?->type)->toBe('receivable')
        ->and($best->model->is($first))->toBeTrue();
});

it('matches client names by whole words only', function () {
    receivableFor('Ana Costa', 100000, '2026-12-31');
    $transaction = BankTransaction::create(['hash' => 'f4', 'booked_on' => '2026-12-10', 'description' => 'TRF SANTANA LDA', 'amount' => 40000, 'import_batch' => 'x']);

    expect(app(MatchBankTransactions::class)->for($transaction)->candidates)->toBeEmpty();
});
