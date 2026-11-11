<?php

use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Models\Payment;
use App\Models\Receivable;
use App\Support\FinanceSettings;
use App\Support\TaxEstimator;
use Livewire\Livewire;

pest()->group('fiscal');

it('leaves tax-exempt payments out of the tax estimate', function () {
    Payment::factory()->create(['paid_on' => '2026-02-10', 'amount' => 100000]);
    Payment::factory()->create(['paid_on' => '2026-02-11', 'amount' => 40000, 'tax_exempt' => true]);

    $estimate = new TaxEstimator(app(FinanceSettings::class), 2026);

    expect($estimate->income())->toBe(100000)
        ->and($estimate->quarters()[0]['income'])->toBe(100000);
})->group('integration');

it('lets you mark a payment as tax-exempt when recording it', function () {
    actingAsAdmin();
    $receivable = Receivable::factory()->create(['amount' => 25000]);

    Livewire::test(ManagePayments::class)->loadTable()
        ->callAction('recordPayment', data: [
            'receivable_id' => $receivable->id,
            'paid_on' => today()->toDateString(),
            'amount' => 250,
            'method' => 'transfer',
            'tax_exempt' => true,
        ])
        ->assertHasNoActionErrors();

    expect($receivable->payments()->sole()->tax_exempt)->toBeTrue();
})->group('ui');

it('is tax-exempt by default while the setting is on', function () {
    app(FinanceSettings::class)->set(['payments_exempt_by_default' => true]);
    expect(Payment::factory()->state(['tax_exempt' => null])->create()->fresh()->tax_exempt)->toBeTrue();
})->group('integration');

it('is not tax-exempt by default once the setting is off', function () {
    app(FinanceSettings::class)->set(['payments_exempt_by_default' => false]);

    expect(Payment::factory()->state(['tax_exempt' => null])->create()->fresh()->tax_exempt)->toBeFalse();
})->group('integration');

it('comes ticked when recording a payment for a project', function () {
    actingAsAdmin();
    app(FinanceSettings::class)->set(['payments_exempt_by_default' => true]);
    Receivable::factory()->create(['amount' => 8000]);

    Livewire::test(ManagePayments::class)->loadTable()
        ->mountAction('recordPayment')
        ->assertActionDataSet(['tax_exempt' => true]);
})->group('ui');
