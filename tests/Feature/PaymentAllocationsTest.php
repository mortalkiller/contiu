<?php

use App\Actions\ApplyBankTransaction;
use App\Actions\RecordAllocatedPayment;
use App\Enums\PaymentMethod;
use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

it('attributes a split payment to both balances while counting the transfer once', function () {
    $client = Client::factory()->create();
    $project = Project::factory()->for($client)->create();
    $first = Receivable::factory()->for($project, 'billable')->create(['amount' => 50000]);
    $second = Receivable::factory()->for($project, 'billable')->create(['amount' => 60000]);

    $payment = app(RecordAllocatedPayment::class)->handle([
        'amount' => 70000, 'paid_on' => today(), 'method' => PaymentMethod::Transfer,
    ], [$first->id => 40000, $second->id => 30000]);

    expect($payment->allocations()->count())->toBe(2)
        ->and($first->fresh()->outstanding)->toBe(10000)
        ->and($second->fresh()->outstanding)->toBe(30000)
        ->and(Payment::sum('amount'))->toBe(70000);
})->group('integration');

it('reconciles a split payment as one bank movement and preserves both allocations on undo', function () {
    $project = Project::factory()->create();
    $first = Receivable::factory()->for($project, 'billable')->create(['amount' => 50001]);
    $second = Receivable::factory()->for($project, 'billable')->create(['amount' => 60003]);
    $payment = app(RecordAllocatedPayment::class)->handle([
        'amount' => 70003, 'paid_on' => today(), 'method' => PaymentMethod::Transfer,
    ], [$first->id => 40001, $second->id => 30002]);
    $line = BankTransaction::create([
        'hash' => 'split-cent-check', 'booked_on' => today(), 'description' => 'Transferência repartida',
        'amount' => 70003, 'import_batch' => 'demo',
    ]);
    $action = app(ApplyBankTransaction::class);
    $action->linkRecords($line, [$payment]);

    expect($line->refresh()->difference())->toBe(0)
        ->and($line->links()->count())->toBe(1)
        ->and($first->fresh()->outstanding)->toBe(10000)
        ->and($second->fresh()->outstanding)->toBe(30001);

    $action->undo($line);

    expect($payment->allocations()->count())->toBe(2)
        ->and($first->fresh()->outstanding)->toBe(10000)
        ->and($second->fresh()->outstanding)->toBe(30001);
})->group('integration');

it('rejects an allocation above the balance without recording a payment', function () {
    $project = Project::factory()->create();
    $first = Receivable::factory()->for($project, 'billable')->create(['amount' => 50000]);
    $second = Receivable::factory()->for($project, 'billable')->create(['amount' => 60000]);

    expect(fn () => app(RecordAllocatedPayment::class)->handle([
        'amount' => 70000, 'paid_on' => today(), 'method' => PaymentMethod::Transfer,
    ], [$first->id => 60000, $second->id => 10000]))->toThrow(ValidationException::class);

    expect(Payment::count())->toBe(0);
})->group('integration');

it('records the split through the payments screen', function () {
    actingAsAdmin();
    $project = Project::factory()->create();
    $first = Receivable::factory()->for($project, 'billable')->create(['amount' => 50001]);
    $second = Receivable::factory()->for($project, 'billable')->create(['amount' => 60003]);

    Livewire::test(ManagePayments::class)
        ->callAction('recordSplitPayment', [
            'paid_on' => today()->toDateString(),
            'method' => PaymentMethod::Transfer->value,
            'amount' => '700.03',
            'allocations' => [
                ['receivable_id' => $first->id, 'amount' => '400.01'],
                ['receivable_id' => $second->id, 'amount' => '300.02'],
            ],
        ])->assertHasNoFormErrors();

    expect(Payment::query()->sole()->amount)->toBe(70003);
})->group('ui');
