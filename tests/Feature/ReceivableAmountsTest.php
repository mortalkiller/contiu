<?php

use App\Enums\ReceivableStatus;
use App\Models\Payment;
use App\Models\Receivable;

it('is open with the full amount outstanding when nothing was paid', function () {
    $receivable = Receivable::factory()->create(['amount' => 50000, 'due_on' => today()->addDay()]);

    expect($receivable->outstanding)->toBe(50000)
        ->and($receivable->status)->toBe(ReceivableStatus::Open);
});

it('is partial after a payment smaller than the amount', function () {
    $receivable = Receivable::factory()->create(['amount' => 50000, 'due_on' => today()->addDay()]);
    Payment::factory()->for($receivable)->create(['amount' => 20000]);

    expect($receivable->fresh()->outstanding)->toBe(30000)
        ->and($receivable->fresh()->status)->toBe(ReceivableStatus::Partial);
});

it('is overdue when its due date has passed with money still open', function () {
    $receivable = Receivable::factory()->create(['amount' => 50000, 'due_on' => today()->subDay()]);

    expect($receivable->status)->toBe(ReceivableStatus::Overdue);
});

it('is paid once payments cover the amount, even after the due date', function () {
    $receivable = Receivable::factory()->create(['amount' => 50000, 'due_on' => today()->subMonth()]);
    Payment::factory()->for($receivable)->create(['amount' => 50000]);

    expect($receivable->fresh()->status)->toBe(ReceivableStatus::Paid)
        ->and(Receivable::query()->open()->count())->toBe(0);
});
