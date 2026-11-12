<?php

namespace App\Support;

use App\Enums\PortugueseVatRegion;
use InvalidArgumentException;

final class IrsSettlementEstimate
{
    /**
     * The year the brackets are known for (config `contiu.irs_brackets`, art. 68.º CIRS). The page
     * only offers the settlement for it: another year's income needs that year's tables.
     */
    public const int YEAR = 2026;

    /**
     * Why the settlement of a fiscal residence cannot be worked out, or null when it can. The
     * Azores reduce the national rates by 30 % (RA001, DLR 2/99/A, art. 4.º), but how that reaches
     * the average rates, the solidarity surcharge and the other subtotals needs the published
     * regional table, which is not loaded: no figure is shown until it is.
     */
    public static function toConfirm(PortugueseVatRegion $residence): ?string
    {
        return $residence === PortugueseVatRegion::Azores
            ? __('The Azores reduce the national personal income tax rates by 30%, but the regional table for :year is not loaded here, so no figure is shown. Confirm it with the IRS simulator on the Portal das Finanças or with your accountant.', ['year' => self::YEAR])
            : null;
    }

    /**
     * Individual taxation on an already established taxable base, with non-refundable deductions,
     * at the general rates of the fiscal residence: the national table, or Madeira's own table with
     * its published average rates (RA002, RAM04; IRS11–IRS13 for the first bracket's limit). The
     * solidarity surcharge (art. 68.º-A) stays the national one.
     *
     * @return array{grossTax: int, solidarity: int, taxAfterDeductions: int, payable: int, refund: int}
     */
    public static function calculate(int $year, int $taxable, int $deductions, int $withholding, int $advancePayments, PortugueseVatRegion $residence = PortugueseVatRegion::Mainland): array
    {
        $inputs = [$taxable, $deductions, $withholding, $advancePayments];

        $brackets = self::toConfirm($residence) === null ? YearlyParameter::irsBrackets($residence->value, $year) : null;

        if ($brackets === null || min($inputs) < 0 || max($inputs) > 99_999_999_999) {
            throw new InvalidArgumentException(__('The data is outside the scope of this personal income tax estimate.'));
        }

        $lower = 0;
        $lowerAverage = 0;
        $weighted = 0;

        foreach ($brackets as [$upper, $marginal, $average]) {
            $upper ??= PHP_INT_MAX;

            if ($taxable < $upper) {
                $weighted = $lower * $lowerAverage + ($taxable - $lower) * $marginal;
                break;
            }

            if ($taxable === $upper) {
                $weighted = $upper * $average;
                break;
            }

            $lower = $upper;
            $lowerAverage = $average;
        }

        $grossTax = self::roundWeighted($weighted);
        $solidarity = self::roundWeighted(
            max(0, min($taxable, 25_000_000) - 8_000_000) * 2500
            + max(0, $taxable - 25_000_000) * 5000,
        );
        $afterDeductions = max(0, $grossTax + $solidarity - $deductions);
        $balance = $afterDeductions - $withholding - $advancePayments;

        return [
            'grossTax' => $grossTax,
            'solidarity' => $solidarity,
            'taxAfterDeductions' => $afterDeductions,
            'payable' => $balance >= 2500 ? $balance : 0,
            'refund' => $balance <= -1000 ? -$balance : 0,
        ];
    }

    private static function roundWeighted(int $value): int
    {
        return intdiv($value + 50_000, 100_000);
    }
}
