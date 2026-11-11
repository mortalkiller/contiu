<?php

use App\Support\FiscalDocumentTotals;
use App\Support\FiscalTerms;

/*
 * The sums of a fiscal document: thousandths of a quantity, cents and basis points in, cents out,
 * always rounded half up.
 */

it('adds the lines, the discounts and the withholding of an exempt invoice', function () {
    $totals = FiscalDocumentTotals::of([
        ['quantity' => 1000, 'unit_price' => 15000],
        ['quantity' => 2000, 'unit_price' => 4500],
        ['quantity' => 1000, 'unit_price' => 6000, 'discount_basis_points' => 1000],
    ], withholdingBasisPoints: 2300);

    expect($totals->gross)->toBe(30000)
        ->and($totals->discount)->toBe(600)
        ->and($totals->net)->toBe(29400)
        ->and($totals->vat)->toBe(0)
        ->and($totals->total)->toBe(29400)
        ->and($totals->withholding)->toBe(6762)
        ->and($totals->payable())->toBe(22638)
        ->and(array_column($totals->lines, 'net'))->toBe([15000, 9000, 5400]);
});

it('charges VAT once per rate on the sum of that rate, not line by line', function () {
    // Three lines of 0,33 € at 23%: 7,59 cents each (8 after rounding), 22,77 cents together (23).
    $totals = FiscalDocumentTotals::of(array_fill(0, 3, ['quantity' => 1000, 'unit_price' => 33, 'vat_basis_points' => 2300]));

    expect(array_sum(array_column($totals->lines, 'vat')))->toBe(24)
        ->and($totals->vat)->toBe(23)
        ->and($totals->total)->toBe(122);
});

it('keeps rates apart when a document mixes them', function () {
    $totals = FiscalDocumentTotals::of([
        ['quantity' => 1000, 'unit_price' => 10000, 'vat_basis_points' => 2300],
        ['quantity' => 1000, 'unit_price' => 10000, 'vat_basis_points' => 600],
        ['quantity' => 1000, 'unit_price' => 10000],
    ]);

    expect($totals->net)->toBe(30000)->and($totals->vat)->toBe(2900)->and($totals->total)->toBe(32900);
});

it('rounds a fractional quantity half up to the cent', function (int $quantity, int $price, int $expected) {
    expect(FiscalDocumentTotals::line($quantity, $price)['gross'])->toBe($expected);
})->with([
    'an hour and a half' => [1500, 4500, 6750],
    'a third of a unit rounds down' => [333, 100, 33],
    'half a cent rounds up' => [500, 1, 1],
    'nothing' => [0, 4500, 0],
]);

it('is empty for a document without lines', function () {
    $totals = FiscalDocumentTotals::of([], 2300);

    expect([$totals->net, $totals->vat, $totals->total, $totals->withholding, $totals->lines])->toBe([0, 0, 0, 0, []]);
});

it('accepts only real Portuguese tax numbers', function (mixed $value, ?string $expected) {
    expect(FiscalTerms::nif($value))->toBe($expected);
})->with([
    'valid' => ['508025338', '508025338'],
    'with the country prefix' => ['PT508025338', '508025338'],
    'wrong check digit' => ['508025339', null],
    'the placeholder for an unknown buyer' => ['999999990', null],
    'not a string' => [508025338, null],
]);

it('reads exact two-decimal amounts and refuses anything else', function (mixed $value, ?int $expected) {
    expect(FiscalTerms::hundredths($value))->toBe($expected);
})->with([
    'a string' => ['294.00', 29400],
    'a comma' => ['12,5', 1250],
    'an integer' => [10, 1000],
    'negative' => ['-50.00', -5000],
    'a float' => [0.1, 10],
    'three decimals' => ['1.005', null],
    'text' => ['abc', null],
]);
