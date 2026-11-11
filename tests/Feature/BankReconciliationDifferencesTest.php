<?php

use App\Actions\ApplyBankTransaction;
use App\Enums\BankTransactionStatus;
use App\Enums\DifferenceExplanation;
use App\Models\BankTransaction;
use App\Models\Expense;
use App\Models\OneOffIncome;
use App\Models\Payment;
use Illuminate\Support\Carbon;

pest()->group('integration', 'banking');

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-11-06')));

it('links with a difference without an explanation: the company account balance shows it', function () {
    $expense = Expense::factory()->create(['net_amount' => 15198]);
    $transaction = BankTransaction::create(['hash' => 'x1', 'booked_on' => '2026-10-06', 'description' => 'TRF P/ Ordenado', 'amount' => -15100, 'import_batch' => 'x']);

    app(ApplyBankTransaction::class)->linkRecords($transaction, [$expense]);

    expect($transaction->fresh()->status)->toBe(BankTransactionStatus::Matched)
        ->and($transaction->fresh()->difference())->toBe(98)
        ->and(Expense::count())->toBe(1);
});

it('rejects an explanation that does not fit the difference', function () {
    $payment = Payment::factory()->create(['amount' => 100000]);
    $transaction = BankTransaction::create(['hash' => 'x2', 'booked_on' => '2026-11-02', 'description' => 'TRF', 'amount' => 101000, 'import_batch' => 'x']);

    expect(fn () => app(ApplyBankTransaction::class)->linkRecords($transaction, [$payment], DifferenceExplanation::BankFee))->toThrow(DomainException::class);
});

it('creates a bank fee expense when less arrived than was received', function () {
    $payment = Payment::factory()->create(['amount' => 100000]);
    $transaction = BankTransaction::create(['hash' => 'x3', 'booked_on' => '2026-11-02', 'description' => 'TRF CLIENTE', 'amount' => 99500, 'import_batch' => 'x']);

    app(ApplyBankTransaction::class)->linkRecords($transaction, [$payment], DifferenceExplanation::BankFee);

    $fee = Expense::sole();

    expect($fee)->total->toBe(500)->is_paid->toBeTrue()
        ->and($fee->supplier->name)->toBe('Banco')
        ->and($fee->category->name)->toBe('Comissões')
        ->and($transaction->fresh()->difference())->toBe(0);
});

it('creates a bank fee expense when more left the account than the expense', function () {
    $expense = Expense::factory()->create(['net_amount' => 15198]);
    $transaction = BankTransaction::create(['hash' => 'x4', 'booked_on' => '2026-10-06', 'description' => 'TRF', 'amount' => -16000, 'import_batch' => 'x']);

    app(ApplyBankTransaction::class)->linkRecords($transaction, [$expense], DifferenceExplanation::BankFee);

    expect($transaction->fresh()->difference())->toBe(0)
        ->and(Expense::where('id', '!=', $expense->id)->sole()->total)->toBe(802);
});

it('records extra money on a credit as another income', function () {
    $payment = Payment::factory()->create(['amount' => 100000]);
    $transaction = BankTransaction::create(['hash' => 'x5', 'booked_on' => '2026-11-02', 'description' => 'TRF CLIENTE', 'amount' => 101000, 'import_batch' => 'x']);

    app(ApplyBankTransaction::class)->linkRecords($transaction, [$payment], DifferenceExplanation::OtherIncome);

    expect(OneOffIncome::sole()->net_amount)->toBe(1000)
        ->and($transaction->fresh()->difference())->toBe(0);
});

it('nets a client payment and an expense paid from the personal account in one transfer', function () {
    $payment = Payment::factory()->create(['amount' => 73000]);
    $chatGpt = Expense::factory()->create(['net_amount' => 8374]);
    $transaction = BankTransaction::create(['hash' => 'x7', 'booked_on' => '2026-11-03', 'description' => 'TRF DE PEDRO', 'amount' => 64626, 'import_batch' => 'x']);

    app(ApplyBankTransaction::class)->applyTargets($transaction, ['payment:'.$payment->id, 'paid:'.$chatGpt->id]);

    expect($transaction->fresh()->difference())->toBe(0)
        ->and($transaction->fresh()->status)->toBe(BankTransactionStatus::Matched);
});

it('offers only the explanations that fit each case', function () {
    expect(DifferenceExplanation::optionsFor(credit: true, lineExceeds: true))->toBe([DifferenceExplanation::OtherIncome])
        ->and(DifferenceExplanation::optionsFor(credit: true, lineExceeds: false))->toBe([DifferenceExplanation::BankFee])
        ->and(DifferenceExplanation::optionsFor(credit: false, lineExceeds: true))->toBe([DifferenceExplanation::BankFee])
        ->and(DifferenceExplanation::optionsFor(credit: false, lineExceeds: false))->toBe([]);
    expect(DifferenceExplanation::cases())->toHaveCount(2);
});
