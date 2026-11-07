<?php

use App\Support\Money;

it('formats cents as euros in Portuguese', function () {
    expect(Money::format(196550))->toBe(Money::format(196550))
        ->and(str_replace(["\u{00A0}", "\u{202F}"], ' ', Money::format(196550)))->toBe('1 965,50 €')
        ->and(str_replace(["\u{00A0}", "\u{202F}"], ' ', Money::format(-15100)))->toBe('-151,00 €')
        ->and(str_replace(["\u{00A0}", "\u{202F}"], ' ', Money::format(null)))->toBe('0,00 €');
});

it('converts typed euros to cents without float noise', function (mixed $euros, ?int $cents) {
    expect(Money::toCents($euros))->toBe($cents);
})->with([
    [151.98, 15198],
    ['151.98', 15198],
    ['151,98', 15198],
    [0.1 + 0.2, 30],
    [1500, 150000],
    ['-151.00', -15100],
    [4.325, 433],
    ['', null],
    [null, null],
]);

it('converts cents back to euros for forms and charts', function () {
    expect(Money::toEuros(15198))->toBe(151.98)
        ->and(Money::toEuros(null))->toBeNull();
});

it('rounds VAT half up to the cent, as invoices do', function () {
    expect(Money::vat(2233, '0.2300'))->toBe(514)
        ->and(Money::vat(1000, 0.23))->toBe(230)
        ->and(Money::vat(250, '0.0600'))->toBe(15)
        ->and(Money::vat(2233, '0.0000'))->toBe(0);
});

it('takes the net amount out of a gross amount', function () {
    expect(Money::netFromGross(2747, '0.2300'))->toBe(2233)
        ->and(Money::netFromGross(12300, 0.23))->toBe(10000)
        ->and(Money::netFromGross(8374, '0.0000'))->toBe(8374);
});

it('adds many small amounts exactly', function () {
    $total = array_sum(array_fill(0, 10, Money::toCents(0.1)));

    expect($total)->toBe(100);
});
