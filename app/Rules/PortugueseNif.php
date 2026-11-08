<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A Portuguese NIF: nine digits, the last one a mod-11 check digit over the first eight
 * (weights 9 down to 2; a result of 10 or 11 becomes 0).
 */
class PortugueseNif implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::isValid($value)) {
            $fail('O NIF não é válido.');
        }
    }

    public static function isValid(mixed $value): bool
    {
        if (! is_string($value) && ! is_int($value)) {
            return false;
        }

        $nif = (string) $value;

        if (preg_match('/^\d{9}$/', $nif) !== 1) {
            return false;
        }

        $sum = 0;

        for ($i = 0; $i < 8; $i++) {
            $sum += (int) $nif[$i] * (9 - $i);
        }

        $check = 11 - ($sum % 11);

        return ($check >= 10 ? 0 : $check) === (int) $nif[8];
    }
}
