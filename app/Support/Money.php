<?php

namespace App\Support;

use App\Enums\PortugueseVatRegion;
use Illuminate\Support\Number;

/**
 * Money is stored and computed in integer cents (as banks do), so sums and comparisons are exact.
 * Euros only exist at the edges: what the user types, sees, imports and receives by email.
 * Every rounding goes through here: half up, to the cent.
 */
final class Money
{
    /** "1 965,50 €" from 196550 cents. */
    public static function format(?int $cents, ?string $locale = null): string
    {
        return (string) Number::currency(($cents ?? 0) / 100, 'EUR', $locale ?? app()->getLocale());
    }

    /** Euros as typed or imported ("151,98", "151.98", 151.98) → 15198 cents. */
    public static function toCents(float|int|string|null $euros): ?int
    {
        if ($euros === null || (is_string($euros) && trim($euros) === '')) {
            return null;
        }

        $value = is_string($euros) ? (float) str_replace([' ', ','], ['', '.'], trim($euros)) : (float) $euros;

        return self::round($value * 100);
    }

    /** 15198 → 151.98, for form fields and chart axes. */
    public static function toEuros(?int $cents): ?float
    {
        return $cents === null ? null : round($cents / 100, 2);
    }

    /**
     * Cents → euros for a chart dataset (axes and tooltips read euros).
     *
     * @param  array<int|string, int>  $cents
     * @return array<int|string, float>
     */
    public static function toEurosList(array $cents): array
    {
        return array_map(fn ($value) => round((int) $value / 100, 2), $cents);
    }

    /** VAT of a net amount at a rate (0.23), rounded per document line. */
    public static function vat(int $netCents, float|string $rate): int
    {
        return self::round($netCents * (float) $rate);
    }

    /** Net amount inside a gross amount that includes VAT at a rate. */
    public static function netFromGross(int $grossCents, float|string $rate): int
    {
        return self::round($grossCents / (1 + (float) $rate));
    }

    /** A share of an amount (e.g. 70 % of income), rounded to the cent. */
    public static function share(int $cents, float|string $rate): int
    {
        return self::round($cents * (float) $rate);
    }

    /** The single rounding rule: half up, to the cent. */
    public static function round(float $cents): int
    {
        return (int) round($cents, 0, PHP_ROUND_HALF_UP);
    }

    /**
     * VAT options keyed like the `decimal:4` cast returns them, so stored values match.
     *
     * @return array<string, string>
     */
    public static function vatOptions(): array
    {
        $rates = [0];
        foreach (PortugueseVatRegion::cases() as $region) {
            array_push($rates, ...array_values($region->rates()));
        }

        sort($rates);
        $options = [];
        foreach (array_unique($rates) as $rate) {
            $options[sprintf('0.%04d', $rate)] = intdiv($rate, 100).'%';
        }

        return $options;
    }
}
