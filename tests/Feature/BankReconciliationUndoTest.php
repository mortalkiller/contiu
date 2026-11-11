<?php

use App\Actions\ApplyBankTransaction;
use App\Enums\DifferenceExplanation;
use App\Models\BankTransaction;
use App\Models\BankTransactionLink;
use App\Models\Expense;
use App\Models\FiscalDocument;
use App\Models\Payment;
use App\Models\Supplier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

pest()->group('integration', 'banking');

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-11-06')));

it('undo deletes a payment created from the line and keeps one that already existed', function () {
    $receivable = receivableFor('Gonçalo Teixeira', 100000, '2026-12-10');
    $existing = Payment::factory()->for($receivable)->create(['amount' => 30000]);
    $created = BankTransaction::create(['hash' => 'u1', 'booked_on' => '2026-12-10', 'description' => 'TRF', 'amount' => 40000, 'import_batch' => 'x']);
    $linked = BankTransaction::create(['hash' => 'u2', 'booked_on' => '2026-12-11', 'description' => 'TRF', 'amount' => 30000, 'import_batch' => 'x']);
    app(ApplyBankTransaction::class)->toReceivable($created, $receivable);
    app(ApplyBankTransaction::class)->linkRecords($linked, [$existing]);

    app(ApplyBankTransaction::class)->undo($created);
    app(ApplyBankTransaction::class)->undo($linked);

    expect(Payment::pluck('id')->all())->toBe([$existing->id])
        ->and(BankTransaction::pending()->count())->toBe(2)
        ->and(BankTransactionLink::count())->toBe(0);
});

it('keeps a reconciled line intact when undo would delete a payment linked to a fiscal document', function () {
    $receivable = receivableFor('Cliente', 40000, '2026-12-10');
    $line = BankTransaction::create(['hash' => 'undo-with-document', 'booked_on' => '2026-12-10', 'description' => 'TRF', 'amount' => 40000, 'import_batch' => 'x']);
    app(ApplyBankTransaction::class)->toReceivable($line, $receivable);
    $payment = $receivable->payments()->sole();
    $status = $line->refresh()->status;
    FiscalDocument::factory()->create()->links()->create(['payment_id' => $payment->id]);

    expect(fn () => app(ApplyBankTransaction::class)->undo($line))
        ->toThrow(DomainException::class, 'documento fiscal');

    expect($line->refresh()->status)->toBe($status)
        ->and($line->links()->count())->toBe(1)
        ->and($payment->fresh())->not->toBeNull();
});

it('keeps the expense when undo would delete its proof', function () {
    $line = BankTransaction::create(['hash' => 'undo-with-proof', 'booked_on' => '2026-12-10', 'description' => 'Despesa', 'amount' => -4000, 'import_batch' => 'x']);
    $expense = app(ApplyBankTransaction::class)->createExpense($line, ['supplier_id' => Supplier::factory()->create()->id]);
    $expense->forceFill(['proof_path' => 'expense-proofs/'.$expense->account_id.'/'.Str::uuid().'.pdf'])->save();

    expect(fn () => app(ApplyBankTransaction::class)->undo($line))->toThrow(DomainException::class, 'comprovativo');

    expect($line->refresh()->links()->count())->toBe(1)
        ->and($expense->fresh()->proof_path)->toBe($expense->proof_path);
});

it('undo puts an expense marked paid by the line back to unpaid', function () {
    $expense = Expense::factory()->unpaid()->create(['net_amount' => 4000]);
    $transaction = BankTransaction::create(['hash' => 'u3', 'booked_on' => '2026-11-02', 'description' => 'DD', 'amount' => -4000, 'import_batch' => 'x']);
    app(ApplyBankTransaction::class)->toExpense($transaction, $expense);

    app(ApplyBankTransaction::class)->undo($transaction);

    expect($expense->fresh())->is_paid->toBeFalse()->paid_on->toBeNull();
});

it('undo returns an own transfer or an ignored line to pending', function () {
    $own = BankTransaction::create(['hash' => 'u4', 'booked_on' => '2026-11-02', 'description' => 'TRF', 'amount' => -4000, 'import_batch' => 'x']);
    $ignored = BankTransaction::create(['hash' => 'u5', 'booked_on' => '2026-11-02', 'description' => 'X', 'amount' => -100, 'import_batch' => 'x']);
    app(ApplyBankTransaction::class)->markOwnTransfer($own);
    app(ApplyBankTransaction::class)->ignore($ignored);

    app(ApplyBankTransaction::class)->undo($own);
    app(ApplyBankTransaction::class)->undo($ignored);

    expect(BankTransaction::pending()->count())->toBe(2);
});

it('undo removes the fee expense and the explanation', function () {
    $payment = Payment::factory()->create(['amount' => 100000]);
    $transaction = BankTransaction::create(['hash' => 'x6', 'booked_on' => '2026-11-02', 'description' => 'TRF', 'amount' => 99500, 'import_batch' => 'x']);
    app(ApplyBankTransaction::class)->linkRecords($transaction, [$payment], DifferenceExplanation::BankFee);

    app(ApplyBankTransaction::class)->undo($transaction);

    expect(Expense::count())->toBe(0)
        ->and(Payment::count())->toBe(1)
        ->and($transaction->fresh()->difference_explanation)->toBeNull();
});
