<?php

use App\Filament\Resources\Retainers\Pages\EditRetainer;
use App\Filament\Resources\Retainers\Pages\ViewRetainer;
use App\Models\Payment;
use App\Models\Receivable;
use App\Models\Retainer;
use Livewire\Livewire;

it('marks payments of a tax-exempt retainer as exempt automatically', function () {
    $retainer = Retainer::factory()->create(['tax_exempt' => true]);
    $period = Receivable::factory()->for($retainer, 'billable')->create();

    expect(Payment::factory()->for($period)->state(['tax_exempt' => null])->create()->fresh()->tax_exempt)->toBeTrue();
});

it('keeps payments of a retainer marked not exempt out of the default', function () {
    $period = Receivable::factory()->for(Retainer::factory()->state(['tax_exempt' => false]), 'billable')->create();

    expect(Payment::factory()->for($period)->state(['tax_exempt' => null])->create()->fresh()->tax_exempt)->toBeFalse();
});

it('creates retainers tax-exempt by default while the setting is on', function () {
    expect(Retainer::factory()->state(['tax_exempt' => null])->create()->fresh()->tax_exempt)->toBeTrue();
});

it('comes ticked when recording a payment for a tax-exempt retainer', function () {
    actingAsAdmin();
    $retainer = Retainer::factory()->create(['tax_exempt' => true]);
    Receivable::factory()->for($retainer, 'billable')->create(['amount' => 20]);

    Livewire::test(ViewRetainer::class, ['record' => $retainer->getRouteKey()])
        ->mountAction('recordPayment')
        ->assertActionDataSet(['tax_exempt' => true]);
});

it('updates the retainer\'s existing payments when its exemption changes', function () {
    actingAsAdmin();
    $retainer = Retainer::factory()->create(['tax_exempt' => false]);
    $paid = Payment::factory()->for(Receivable::factory()->for($retainer, 'billable'))->create();

    Livewire::test(EditRetainer::class, ['record' => $retainer->getRouteKey()])
        ->fillForm(['tax_exempt' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($paid->fresh()->tax_exempt)->toBeTrue();

    Livewire::test(EditRetainer::class, ['record' => $retainer->getRouteKey()])
        ->fillForm(['tax_exempt' => false])
        ->call('save');

    expect($paid->fresh()->tax_exempt)->toBeFalse();
});
