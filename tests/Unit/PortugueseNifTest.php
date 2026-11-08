<?php

use App\Rules\PortugueseNif;

function nifPasses(mixed $value): bool
{
    $failed = false;

    (new PortugueseNif)->validate('nif', $value, function () use (&$failed) {
        $failed = true;
    });

    return ! $failed;
}

it('accepts a NIF with the right check digit', function (string $nif) {
    expect(nifPasses($nif))->toBeTrue();
})->with(['123456789', '501964843']);

it('refuses a NIF that is not nine digits with the right check digit', function (mixed $nif) {
    expect(nifPasses($nif))->toBeFalse();
})->with([
    'wrong check digit' => '123456780',
    'eight digits' => '12345678',
    'letters' => 'abcdefghi',
    'ten digits' => '1234567890',
    'spaces' => '123 456 789',
]);

it('takes a check digit of 10 or 11 as 0', function () {
    // 1·9 + 1·8 + … + 1·2 = 44 = 4·11, so 11 - 0 = 11 → 0.
    expect(nifPasses('111111110'))->toBeTrue()
        ->and(nifPasses('111111111'))->toBeFalse();
});
