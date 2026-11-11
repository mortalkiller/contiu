<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * Portuguese fiscal rules that do not depend on who issues the document: tax numbers, the IRS
 * withholding rates and exact two-decimal parsing of amounts read from outside.
 */
final class FiscalTerms
{
    /** The IRS withholding rates a self-employed issuer applies, in basis points. */
    public const array WITHHOLDING_RATES = [1150 => '11,5%', 2300 => '23%'];

    /** Below this the withholding is waived, in cents. */
    public const int MINIMUM_WITHHOLDING = 2500;

    /** Above this a simplified invoice is not allowed, in cents, for services and for goods sold to individuals. */
    public const array SIMPLIFIED_INVOICE_LIMITS = ['services' => 10000, 'goods' => 100000];

    /** The nine digits of a valid Portuguese tax number, with or without the "PT" prefix, or null. */
    public static function nif(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/\A(?:PT)?([1-9][0-9]{8})\z/i', trim($value), $parts) || $parts[1] === '999999990') {
            return null;
        }

        $nif = $parts[1];
        $weightedSum = 0;

        for ($index = 0; $index < 8; $index++) {
            $weightedSum += (int) $nif[$index] * (9 - $index);
        }

        return ((11 - $weightedSum % 11) % 11) % 10 === (int) $nif[8] ? $nif : null;
    }

    /**
     * The withholding of a document at one of the allowed rates, or null when there is none. Refuses
     * a rate that is not one of them and an amount under the legal minimum.
     *
     * @return array{basisPoints: int, gross: int, retained: int, cash: int}|null
     */
    public static function withholding(int $basisPoints, int $net, int $gross): ?array
    {
        if ($basisPoints === 0) {
            return null;
        }

        if (! isset(self::WITHHOLDING_RATES[$basisPoints])) {
            throw ValidationException::withMessages(['withholdingRate' => __('Choose the withholding rate confirmed for the service.')]);
        }

        $retained = self::withholdingAmount($basisPoints, $net);

        if ($retained < self::MINIMUM_WITHHOLDING) {
            throw ValidationException::withMessages(['withholdingRate' => __('The calculated withholding is below €25. Review the withholding exemption for this document.')]);
        }

        return ['basisPoints' => $basisPoints, 'gross' => $gross, 'retained' => $retained, 'cash' => $gross - $retained];
    }

    /** What the client withholds on a net amount, in cents, rounded half up. */
    public static function withholdingAmount(int $basisPoints, int $net): int
    {
        return intdiv($net * $basisPoints + 5000, 10000);
    }

    /** Exact cents of a two-decimal value read from outside; null when it has unexpected precision. */
    public static function hundredths(mixed $value): ?int
    {
        if (is_float($value)) {
            $value = json_encode($value);
        }

        if ((! is_string($value) && ! is_int($value))
            || ! preg_match('/\A(-?)([0-9]{1,12})(?:[.,]([0-9]{1,2})0*)?\z/', (string) $value, $parts)) {
            return null;
        }

        $cents = (int) $parts[2] * 100 + (int) str_pad($parts[3] ?? '', 2, '0');

        return $parts[1] === '-' ? -$cents : $cents;
    }
}
