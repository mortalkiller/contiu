<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * The optional waiver of IRS withholding on the worker's receipts (art. 101.º-B, n.º 1, a), CIRS,
 * RET004, RET005). It applies while the account chose it, the income of the year before stayed
 * under the art. 53.º limit, and the year's income has not reached that limit: it ends on the first
 * day of the month after the one in which the limit is reached (reaching it exactly counts). The
 * receipts issued with it carry the legal mention (MENTION).
 */
final readonly class WithholdingWaiver
{
    public const string MENTION = 'Dispensa de retenção na fonte — art. 101.º-B, n.º 1, alínea a), do CIRS';

    public function __construct(private FinanceSettings $settings) {}

    public function appliesOn(CarbonImmutable $date): bool
    {
        if (! $this->settings->withholdingWaiver() || ! $this->settings->estimatesTaxes() || $this->previousYearReachedLimit($date->year)) {
            return false;
        }

        $endsOn = $this->endsOn($date->year);

        return $endsOn === null || $date->startOfDay()->lt($endsOn);
    }

    /** The first day without the waiver in the year: the month after the limit is reached; null while it is not. */
    public function endsOn(int $year): ?CarbonImmutable
    {
        $estimator = new TaxEstimator($this->settings, $year);
        $limit = YearlyParameter::vatExemptionLimit($year);
        $start = CarbonImmutable::create($year, 1, 1);

        foreach (range(1, 12) as $month) {
            $end = CarbonImmutable::create($year, $month, 1)->endOfMonth();

            if ($end->gt(CarbonImmutable::today()->endOfMonth())) {
                return null;
            }

            if ($estimator->incomeIn($start, $end) >= $limit) {
                return $end->addDay()->startOfDay();
            }
        }

        return null;
    }

    /** The account chose the waiver but it no longer applies today. */
    public function hasEnded(CarbonImmutable $today): bool
    {
        return $this->settings->withholdingWaiver() && $this->settings->estimatesTaxes() && ! $this->appliesOn($today);
    }

    private function previousYearReachedLimit(int $year): bool
    {
        return (new TaxEstimator($this->settings, $year - 1))->income() >= YearlyParameter::vatExemptionLimit($year - 1);
    }
}
