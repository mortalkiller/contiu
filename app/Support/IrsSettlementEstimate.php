<?php

namespace App\Support;

use InvalidArgumentException;

final class IrsSettlementEstimate
{
    /**
     * The year the brackets are known for (config `contiu.irs_brackets`, art. 68.º CIRS). The page
     * only offers the settlement for it: another year's income needs that year's tables.
     */
    public const int YEAR = 2026;

    /**
     * Individual mainland taxation on an already established taxable base, with non-refundable deductions.
     *
     * @return array{grossTax: int, solidarity: int, taxAfterDeductions: int, payable: int, refund: int}
     */
    public static function calculate(int $year, int $taxable, int $deductions, int $withholding, int $advancePayments): array
    {
        $inputs = [$taxable, $deductions, $withholding, $advancePayments];

        $brackets = config('contiu.irs_brackets')[$year] ?? null;

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
