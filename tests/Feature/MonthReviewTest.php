<?php

use App\Models\Expense;
use App\Models\Payment;
use App\Models\Receivable;
use App\Support\MonthReview;
use Illuminate\Support\Carbon;

pest()->group('integration');

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-03-20')));

it('lists what falls due in the month plus amounts still open from earlier months', function () {
    $due = Receivable::factory()->create(['due_on' => '2026-03-05']);
    $carried = Receivable::factory()->create(['due_on' => '2026-01-10']);
    $paidEarlier = Receivable::factory()->create(['amount' => 5000, 'due_on' => '2026-02-01']);
    Payment::factory()->for($paidEarlier)->create(['amount' => 5000]);
    Receivable::factory()->create(['due_on' => '2026-04-01']);

    $review = new MonthReview(Carbon::parse('2026-03-01'));

    expect($review->receivables()->pluck('id')->all())->toBe([$carried->id, $due->id])
        ->and($review->isCarriedOver($carried))->toBeTrue()
        ->and($review->isCarriedOver($due))->toBeFalse();
});

it('lists the month expenses plus earlier ones still unpaid', function () {
    $march = Expense::factory()->create(['spent_on' => '2026-03-02']);
    $unpaidFebruary = Expense::factory()->unpaid()->create(['spent_on' => '2026-02-27']);
    Expense::factory()->create(['spent_on' => '2026-02-10']);

    $review = new MonthReview(Carbon::parse('2026-03-01'));

    expect($review->expenses()->pluck('id')->all())->toBe([$unpaidFebruary->id, $march->id]);
});

it('sums what came in, what was paid and the result of the month', function () {
    Payment::factory()->create(['paid_on' => '2026-03-03', 'amount' => 30000]);
    Payment::factory()->create(['paid_on' => '2026-02-28', 'amount' => 99900]);
    Expense::factory()->create(['spent_on' => '2026-03-04', 'net_amount' => 10000, 'vat_rate' => 0.23]);
    Expense::factory()->unpaid()->create(['spent_on' => '2026-03-05', 'net_amount' => 4000]);

    $review = new MonthReview(Carbon::parse('2026-03-01'));

    expect($review->received())->toBe(30000)
        ->and($review->paidExpenses())->toBe(12300)
        ->and($review->result())->toBe(17700);
});

it('counts what is left to deal with and closes once everything is treated', function () {
    $receivable = Receivable::factory()->create(['amount' => 10000, 'due_on' => '2026-03-05']);
    $expense = Expense::factory()->unpaid()->create(['spent_on' => '2026-03-02']);

    $review = new MonthReview(Carbon::parse('2026-03-01'));

    expect($review->pendingCount())->toBe(2)
        ->and($review->isClosed())->toBeFalse();

    Payment::factory()->for($receivable)->create(['amount' => 10000]);
    $expense->update(['is_paid' => true, 'paid_on' => '2026-03-02']);

    $review = new MonthReview(Carbon::parse('2026-03-01'));

    expect($review->pendingCount())->toBe(0)
        ->and($review->isClosed())->toBeTrue();
});

it('includes what happens on the last day of the month', function () {
    $receivable = Receivable::factory()->create(['due_on' => '2026-03-31']);
    Payment::factory()->create(['paid_on' => '2026-03-31', 'amount' => 7000]);
    $expense = Expense::factory()->create(['spent_on' => '2026-03-31', 'net_amount' => 3000]);

    $review = new MonthReview(Carbon::parse('2026-03-01'));

    expect($review->receivables()->pluck('id'))->toContain($receivable->id)
        ->and($review->expenses()->pluck('id'))->toContain($expense->id)
        ->and($review->received())->toBe(7000)
        ->and($review->paidExpenses())->toBe(3000);
});

it('lists an amount due later that already received a partial payment this month', function () {
    $januaryJob = Receivable::factory()->create(['amount' => 100000, 'due_on' => '2026-04-30']);
    Payment::factory()->for($januaryJob)->create(['paid_on' => '2026-03-12', 'amount' => 30000]);
    Receivable::factory()->create(['amount' => 50000, 'due_on' => '2026-04-30']); // no payment this month

    $review = new MonthReview(Carbon::parse('2026-03-01'));

    expect($review->receivables()->pluck('id')->all())->toBe([$januaryJob->id])
        ->and($review->isDueLater($januaryJob))->toBeTrue()
        ->and($review->received())->toBe(30000)
        ->and($review->pendingCount())->toBe(0)       // not due yet: it doesn't keep March open
        ->and($review->expected())->toBe(0);        // nor counts as expected for March
});
