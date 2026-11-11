<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * The IRS payments on account of a year (art. 102.º CIRS, PPC001, PPC002), from the assessment of
 * the income of two years before, as the account entered it (FinanceSettings::irsPrepaymentBasis()):
 *
 *     total = factor × (tax × net business income ÷ net total income − business withholding)
 *
 * The factor is the year's (65 % since 2025, 76.5 % before). The total is split in three, each
 * rounded up to the euro; one under 50 € is not due. They are paid by 20 July, 20 September and
 * 20 December, moved by PortugueseDeadline. The tax authority's notice prevails over this figure.
 */
final readonly class IrsPrepayments
{
    /** Cents: an installment below this is not due. */
    public const MINIMUM_INSTALLMENT = 5000;

    private const MONTHS = [1 => 7, 2 => 9, 3 => 12];

    public function __construct(private FinanceSettings $settings) {}

    /**
     * The installments due in the year, by number (1 to 3); none without the assessment of year − 2
     * or when the computed amount is not due.
     *
     * @return array<int, array{amount: int, dueOn: CarbonImmutable}>
     */
    public function forYear(int $year): array
    {
        $installment = $this->installment($year);

        if ($installment === null) {
            return [];
        }

        return collect(self::MONTHS)
            ->map(fn (int $month): array => [
                'amount' => $installment,
                'dueOn' => PortugueseDeadline::of(CarbonImmutable::create($year, $month, 20)),
            ])
            ->all();
    }

    /** Each installment in cents, or null when nothing is due. */
    public function installment(int $year): ?int
    {
        $basis = $this->settings->irsPrepaymentBasis();

        if ($basis === null || $basis['income_year'] !== $year - 2 || $basis['net_total'] <= 0) {
            return null;
        }

        $share = max(0, $basis['net_business']) / $basis['net_total'];
        $total = YearlyParameter::irsPrepaymentFactor($year) * ($basis['tax'] * min(1, $share) - $basis['withheld']);

        if ($total <= 0) {
            return null;
        }

        $installment = (int) ceil($total / 3 / 100) * 100;

        return $installment < self::MINIMUM_INSTALLMENT ? null : $installment;
    }
}
