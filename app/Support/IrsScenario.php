<?php

namespace App\Support;

final class IrsScenario
{
    /**
     * Compare documented billing and withholding with the amounts entered for a hypothetical retainer.
     *
     * @return array{year: int, monthlyNet: int, months: int, baselineBilling: ?int, retainerBilling: int,
     *     scenarioBilling: ?int, baselineRetention: ?int, scenarioRetention: ?int, combinedRetention: ?int,
     *     foreignDocuments: int, staleRetentionRecords: int}
     */
    public static function compare(int $year, int $monthlyNetCents, int $months, ?int $scenarioRetentionCents = null): array
    {
        $turnover = DocumentedTurnover::forYear($year);
        $withholding = WithholdingReview::forYear($year);
        $retainerBilling = $monthlyNetCents * $months;

        return [
            'year' => $year,
            'monthlyNet' => $monthlyNetCents,
            'months' => $months,
            'baselineBilling' => $turnover->billedNet,
            'retainerBilling' => $retainerBilling,
            'scenarioBilling' => $turnover->billedNet === null ? null : $turnover->billedNet + $retainerBilling,
            'baselineRetention' => $withholding->retainedTotal,
            'scenarioRetention' => $scenarioRetentionCents,
            'combinedRetention' => $withholding->retainedTotal === null || $scenarioRetentionCents === null
                ? null
                : $withholding->retainedTotal + $scenarioRetentionCents,
            'foreignDocuments' => $turnover->nonEuroDocumentCount,
            'staleRetentionRecords' => $withholding->rows->where('current', false)->count(),
        ];
    }
}
