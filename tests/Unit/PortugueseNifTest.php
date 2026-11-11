<?php

use App\Rules\PortugueseNif;

it('accepts a NIF with the right check digit', function (string $nif) {
    expect(PortugueseNif::isValid($nif))->toBeTrue();
})->with(['123456789', '501964843']);

it('refuses a NIF that is not nine digits with the right check digit', function (mixed $nif) {
    expect(PortugueseNif::isValid($nif))->toBeFalse();
})->with([
    'wrong check digit' => '123456780',
    'eight digits' => '12345678',
    'letters' => 'abcdefghi',
    'ten digits' => '1234567890',
    'spaces' => '123 456 789',
    'trailing newline' => "123456789\n",
]);

it('takes a check digit of 10 or 11 as 0', function () {
    // 1·9 + 1·8 + … + 1·2 = 44 = 4·11, so 11 - 0 = 11 → 0.
    expect(PortugueseNif::isValid('111111110'))->toBeTrue()
        ->and(PortugueseNif::isValid('111111111'))->toBeFalse();
});
