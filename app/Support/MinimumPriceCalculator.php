<?php

namespace App\Support;

final class MinimumPriceCalculator
{
    /**
     * Amounts are in cents, hours in hundredths of an hour and the reserve in basis points.
     *
     * @return array{annual: int, monthly: int, hourly: int}
     */
    public static function calculate(
        int $annualIncomeGoalCents,
        int $annualCostsCents,
        int $billableMonths,
        int $hoursPerMonthHundredths,
        int $reserveBasisPoints,
    ): array {
        $annualNumerator = ($annualIncomeGoalCents + $annualCostsCents) * 10_000;
        $retainedBasisPoints = 10_000 - $reserveBasisPoints;
        $monthlyDenominator = $retainedBasisPoints * $billableMonths;

        return [
            'annual' => self::divideRoundingUp($annualNumerator, $retainedBasisPoints),
            'monthly' => self::divideRoundingUp($annualNumerator, $monthlyDenominator),
            'hourly' => self::divideRoundingUp($annualNumerator * 100, $monthlyDenominator * $hoursPerMonthHundredths),
        ];
    }

    private static function divideRoundingUp(int $numerator, int $denominator): int
    {
        return intdiv($numerator + $denominator - 1, $denominator);
    }
}
