<?php

use App\Enums\ReconciliationStatus;
use App\Models\BankTransaction;
use App\Models\Expense;
use App\Models\Payment;
use App\Support\CompanyAccount;
use App\Support\Money;
use App\Support\Reconciliation;
use App\Support\TransferCoverage;
use Illuminate\Support\Carbon;

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-11-20')));

function bankLine(string $date, int $amount, int $balance, string $status = 'matched'): BankTransaction
{
    static $n = 0;

    return BankTransaction::create(['hash' => 'rs'.(++$n), 'booked_on' => $date, 'description' => 'TRF', 'amount' => $amount, 'balance' => $balance, 'status' => $status, 'import_batch' => 'b']);
}

function paid(int $amount, string $date): Payment
{
    return Payment::factory()->create(['amount' => $amount, 'paid_on' => $date]);
}

it('reconciles a payment linked to a statement line', function () {
    $line = bankLine('2026-11-01', 50000, 50000);
    $payment = paid(50000, '2026-11-01');
    $line->links()->create(['linkable_type' => $payment->getMorphClass(), 'linkable_id' => $payment->id]);

    expect((new Reconciliation)->forPayment($payment)->label())->toBe('Reconciliado');
});

it('covers the oldest payments first with the money that reached the bank', function () {
    bankLine('2026-11-01', 0, 0); // account opened at zero
    $old = paid(30000, '2026-11-02');
    $middle = paid(40000, '2026-11-03');
    $new = paid(20000, '2026-11-04');
    bankLine('2026-11-05', 35000, 35000, 'own_transfer'); // 350 € reached the bank: 900 − 350 = 550 still missing

    $reconciliation = new Reconciliation;

    expect($reconciliation->forPayment($old)->status)->toBe(ReconciliationStatus::Reconciled)
        ->and($reconciliation->forPayment($middle)->status)->toBe(ReconciliationStatus::NotReconciled)
        ->and($reconciliation->forPayment($middle)->detail())->toBe('Faltam '.Money::format(35000).' de '.Money::format(40000))
        ->and($reconciliation->forPayment($new)->label())->toBe('Por reconciliar')
        ->and($reconciliation->missingPayments())->toBe(55000);
});

it('adds up exactly to what the company account card says is missing', function () {
    bankLine('2026-11-01', 0, 0);
    collect([12345, 6789, 101010, 50])->each(fn (int $amount, int $i) => paid($amount, '2026-11-0'.($i + 2)));
    Expense::factory()->create(['net_amount' => 8374, 'paid_on' => '2026-11-03', 'spent_on' => '2026-11-03']);
    bankLine('2026-11-08', 40000, 40000, 'own_transfer');

    expect((new Reconciliation)->missingPayments())->toBe(-(new CompanyAccount)->difference());
});

it('cannot reconcile what is newer than the last statement', function () {
    bankLine('2026-11-01', 0, 0);
    $later = paid(10000, '2026-11-15');

    expect((new Reconciliation)->forPayment($later))
        ->status->toBe(ReconciliationStatus::NotReconciled)
        ->detail()->toBe('Depois do último extrato (01/11/2026)');
});

it('reconciles expenses the same way', function () {
    bankLine('2026-11-01', 0, 50000); // opening 500 €: nothing before counts
    $covered = Expense::factory()->create(['net_amount' => 2000, 'paid_on' => '2026-11-02', 'spent_on' => '2026-11-02']);
    $pending = Expense::factory()->create(['net_amount' => 8374, 'paid_on' => '2026-11-03', 'spent_on' => '2026-11-03']);
    bankLine('2026-11-04', -2000, 48000, 'own_transfer'); // only 20 € left the bank

    $reconciliation = new Reconciliation;

    expect($reconciliation->forExpense($covered)->label())->toBe('Reconciliado')
        ->and($reconciliation->forExpense($pending)->label())->toBe('Por reconciliar');
});

it('has nothing to reconcile against without a statement', function () {
    $payment = paid(10000, '2026-11-02');

    expect((new Reconciliation)->forPayment($payment))
        ->status->toBe(ReconciliationStatus::NotReconciled)
        ->detail()->toBe('Sem extrato importado');
});

it('lists the statement lines behind each record', function () {
    bankLine('2026-11-01', 0, 0);
    $direct = paid(5000, '2026-11-02');
    $line = bankLine('2026-11-02', 5000, 5000);
    $line->links()->create(['linkable_type' => $direct->getMorphClass(), 'linkable_id' => $direct->id]);
    $outside = paid(30000, '2026-11-03');
    $transfer = bankLine('2026-11-06', 30000, 35000, 'own_transfer');

    $reconciliation = new Reconciliation;

    expect(collect($reconciliation->forPayment($direct)->lines)->map(fn ($by) => [$by['line']->getKey(), $by['amount'], $by['direct']])->all())->toBe([[$line->getKey(), 5000, true]])
        ->and(collect($reconciliation->forPayment($outside)->lines)->map(fn ($by) => [$by['line']->getKey(), $by['amount'], $by['direct']])->all())->toBe([[$transfer->getKey(), 30000, false]]);
});

it('agrees with the transfer details on what is still missing', function () {
    bankLine('2026-11-01', 0, 0);
    $payment = paid(70000, '2026-11-02');
    bankLine('2026-11-05', 30000, 30000, 'own_transfer');

    expect((new Reconciliation)->forPayment($payment)->missing)->toBe((new TransferCoverage)->remaining($payment));
});
