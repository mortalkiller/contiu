<?php

use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Models\Payment;
use App\Models\Receivable;
use App\Support\FinanceSettings;
use App\Support\TaxEstimator;
use Livewire\Livewire;

it('leaves tax-exempt payments out of the tax estimate', function () {
    Payment::factory()->create(['paid_on' => '2026-02-10', 'amount' => 1000]);
    Payment::factory()->create(['paid_on' => '2026-02-11', 'amount' => 400, 'tax_exempt' => true]);

    $estimate = new TaxEstimator(app(FinanceSettings::class), 2026);

    expect($estimate->income())->toBe(1000.0)
        ->and($estimate->quarters()[0]['income'])->toBe(1000.0);
});

it('lets you mark a payment as tax-exempt when recording it', function () {
    actingAsAdmin();
    $receivable = Receivable::factory()->create(['amount' => 250]);

    Livewire::test(ManagePayments::class)
        ->callAction('recordPayment', data: [
            'receivable_id' => $receivable->id,
            'paid_on' => today()->toDateString(),
            'amount' => 250,
            'method' => 'transfer',
            'tax_exempt' => true,
        ])
        ->assertHasNoActionErrors();

    expect($receivable->payments()->sole()->tax_exempt)->toBeTrue();
});

it('is tax-exempt by default while the setting is on', function () {
    expect(Payment::factory()->state(['tax_exempt' => null])->create()->fresh()->tax_exempt)->toBeTrue();
});

it('is not tax-exempt by default once the setting is off', function () {
    app(FinanceSettings::class)->set(['payments_exempt_by_default' => false]);

    expect(Payment::factory()->state(['tax_exempt' => null])->create()->fresh()->tax_exempt)->toBeFalse();
});

it('comes ticked when recording a payment for a project', function () {
    actingAsAdmin();
    Receivable::factory()->create(['amount' => 80]);

    Livewire::test(ManagePayments::class)
        ->mountAction('recordPayment')
        ->assertActionDataSet(['tax_exempt' => true]);
});
