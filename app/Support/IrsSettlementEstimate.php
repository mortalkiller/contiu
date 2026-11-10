<?php

namespace App\Support;

use InvalidArgumentException;

final class IrsSettlementEstimate
{
    public const int YEAR = 2026;

    /** Article 68: upper limit in cents, normal and average rates in thousandths of a percent. */
    private const array BRACKETS = [
        [834200, 12200, 12200],
        [1258700, 15200, 13212],
        [1783800, 20700, 15416],
        [2308900, 23600, 17277],
        [2939700, 30600, 20136],
        [4309000, 34600, 24732],
        [4656600, 43100, 26103],
        [8663400, 44600, 34658],
        [PHP_INT_MAX, 48000, 0],
    ];

    /**
     * Individual mainland taxation on an already established taxable base, with non-refundable deductions.
     *
     * @return array{grossTax: int, solidarity: int, taxAfterDeductions: int, payable: int, refund: int}
     */
    public static function calculate(int $year, int $taxable, int $deductions, int $withholding, int $advancePayments): array
    {
        $inputs = [$taxable, $deductions, $withholding, $advancePayments];

        if ($year !== self::YEAR || min($inputs) < 0 || max($inputs) > 99_999_999_999) {
            throw new InvalidArgumentException('Os dados estão fora do âmbito desta estimativa de IRS.');
        }

        $lower = 0;
        $lowerAverage = 0;
        $weighted = 0;

        foreach (self::BRACKETS as [$upper, $marginal, $average]) {
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
