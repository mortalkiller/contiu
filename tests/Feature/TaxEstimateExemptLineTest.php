<?php

use App\Filament\Pages\TaxEstimate;
use App\Models\Payment;
use App\Support\FinanceSettings;
use App\Support\Money;
use App\Support\TaxEstimator;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

pest()->group('fiscal');

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-06'));
    Payment::factory()->create(['paid_on' => '2026-03-10', 'amount' => 100000]);
    Payment::factory()->create(['paid_on' => '2026-04-10', 'amount' => 22000, 'tax_exempt' => true]);
    Payment::factory()->create(['paid_on' => '2025-05-10', 'amount' => 7000, 'tax_exempt' => true]);
});

it('adds up the exempt payments it left out', function () {
    expect((new TaxEstimator(app(FinanceSettings::class), 2026))->exemptIncome())->toBe(22000)
        ->and((new TaxEstimator(app(FinanceSettings::class), 2025))->exemptIncome())->toBe(7000);
})->group('integration');

it('says on the page how much was left out as exempt', function () {
    actingAsAdmin();

    Livewire::test(TaxEstimate::class)
        ->assertSee('Excluídos '.Money::format(22000).' de recebimentos isentos')
        ->set('year', 2025)
        ->assertSee('Excluídos '.Money::format(7000).' de recebimentos isentos')
        ->assertDontSee('Excluídos '.Money::format(22000));
})->group('ui');

it('still says so when nothing is exempt, pointing to where to mark it', function () {
    actingAsAdmin();
    Payment::query()->update(['tax_exempt' => false]);

    Livewire::test(TaxEstimate::class)
        ->assertSee('Nenhum recebimento isento de impostos')
        ->assertSee('marca-os em Recebimentos ou na avença');
})->group('ui');
