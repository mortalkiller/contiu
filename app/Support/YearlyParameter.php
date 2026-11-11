<?php

namespace App\Support;

/**
 * Fiscal values that change from year to year, read from `config/contiu.php` (one table per value,
 * keyed by year). The year is the one the value applies to: a contribution of January 2027 uses
 * the 2027 IAS, the IRS of 2026 income uses the 2026 tables.
 *
 * A year missing from a table uses the closest earlier year (or the earliest, before the table
 * starts); isProvisional() says when that happened, so the screens can say the figure is provisional.
 */
final class YearlyParameter
{
    /** Indexante dos Apoios Sociais, in cents (SS080). */
    public static function ias(int $year): int
    {
        return (int) self::value('ias', $year);
    }

    /** Art. 53.º CIVA: the yearly turnover limit of the VAT exemption, in cents (IVA07, IVA08). */
    public static function vatExemptionLimit(int $year): int
    {
        return (int) self::value('vat_exemption_limit', $year);
    }

    /**
     * Art. 58.º CIVA, since the 2025 reform: above this turnover the exemption ends at once, in
     * cents (IVA10). Null before 2025, when the exit rules were different.
     */
    public static function vatExemptionTolerance(int $year): ?int
    {
        return $year < 2025 ? null : (int) self::value('vat_exemption_tolerance', $year);
    }

    /** Art. 25.º, n.º 1, a), CIRS: the automatic part of the 15 % expenses check, in cents (DES003). */
    public static function irsSpecificDeduction(int $year): int
    {
        return (int) self::value('irs_specific_deduction', $year);
    }

    /** Art. 102.º CIRS: the share of the computed amount paid on account (PPC002). */
    public static function irsPrepaymentFactor(int $year): float
    {
        return (float) self::value('irs_prepayment_factor', $year);
    }

    public static function isProvisional(string $table, int $year): bool
    {
        return ! array_key_exists($year, self::table($table));
    }

    public static function value(string $table, int $year): mixed
    {
        $values = self::table($table);

        if (array_key_exists($year, $values)) {
            return $values[$year];
        }

        $earlier = array_filter(array_keys($values), fn (int $known): bool => $known < $year);

        return $values[$earlier === [] ? min(array_keys($values)) : max($earlier)];
    }

    /** @return array<int, mixed> */
    private static function table(string $table): array
    {
        return config('contiu.'.$table);
    }
}
