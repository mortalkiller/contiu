<?php

use App\Enums\Seat;
use App\Models\Expense;
use App\Models\Supplier;
use App\Support\FinanceSettings;
use App\Support\ReverseChargeVat;
use Carbon\CarbonImmutable;

function foreignExpense(Seat $seat, array $attributes = []): Expense
{
    return Expense::factory()->for(Supplier::factory()->create(['seat' => $seat]), 'supplier')->create($attributes + [
        'spent_on' => '2026-10-15', 'is_goods' => false, 'vat_rate' => 0, 'net_amount' => 1000,
    ]);
}

function reverseCharge(): ReverseChargeVat
{
    return new ReverseChargeVat(app(FinanceSettings::class));
}

$october = fn () => CarbonImmutable::parse('2026-10-01');

it('self-assesses VAT on services bought from foreign suppliers', function () use ($october) {
    foreignExpense(Seat::EuropeanUnion, ['net_amount' => 8374]);
    foreignExpense(Seat::OutsideEu, ['net_amount' => 1000, 'is_paid' => false, 'paid_on' => null]);

    $result = reverseCharge()->forMonth($october());

    expect($result['base'])->toBe(9374)
        ->and($result['vat'])->toBe(2156)
        ->and($result['expenses'])->toHaveCount(2);
});

it('leaves out goods, Portuguese suppliers and other months', function () use ($october) {
    foreignExpense(Seat::EuropeanUnion, ['is_goods' => true]);
    foreignExpense(Seat::Portugal);
    foreignExpense(Seat::EuropeanUnion, ['spent_on' => '2026-09-30']);
    foreignExpense(Seat::EuropeanUnion, ['spent_on' => '2026-11-01']);

    $result = reverseCharge()->forMonth($october());

    expect($result['base'])->toBe(0)
        ->and($result['vat'])->toBe(0)
        ->and($result['expenses'])->toHaveCount(0);
});

it('lists foreign expenses that carry VAT instead of self-assessing them', function () use ($october) {
    $withVat = foreignExpense(Seat::EuropeanUnion, ['vat_rate' => 0.23]);
    foreignExpense(Seat::EuropeanUnion);

    expect(reverseCharge()->forMonth($october())['expenses'])->toHaveCount(1)
        ->and(reverseCharge()->foreignWithVat($october())->pluck('id')->all())->toBe([$withVat->id]);
});

it('uses the configured reverse-charge rate', function () use ($october) {
    app(FinanceSettings::class)->set(['reverse_charge_vat_rate' => 0.22]);
    foreignExpense(Seat::EuropeanUnion, ['net_amount' => 10000]);

    expect(reverseCharge()->forMonth($october())['vat'])->toBe(2200);
});
