<?php

namespace App\Support;

use App\Models\Expense;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Rough tax picture for an ENI in the simplified regime. ESTIMATES ONLY — confirm with an accountant.
 *
 * - "Rendimento relevante" is approximated by the payments received (fatura-recibo is issued on payment).
 * - Segurança Social (trabalhador independente): each quarter's income is declared in the following
 *   month and sets the contribution of the next three months: base = income × relevant share / 3,
 *   contribution = base × rate. Months within the first 12 months of activity are exempt when the
 *   first-year exemption applies.
 * - IRS: exempt (setting) or taxable income = 75% of services income; the tax itself is not computed.
 * - IVA: art. 53.º exemption → progress against the yearly threshold; otherwise VAT collected vs paid.
 */
final class TaxEstimator
{
    public const QUARTER_LABELS = ['Jan – Mar', 'Abr – Jun', 'Jul – Set', 'Out – Dez'];

    public const IRS_SERVICES_COEFFICIENT = 0.75;

    /** @var array<string, float> */
    private array $incomeCache = [];

    public function __construct(private readonly FinanceSettings $settings, public readonly int $year) {}

    public function settings(): FinanceSettings
    {
        return $this->settings;
    }

    public function income(): float
    {
        return $this->incomeBetween(CarbonImmutable::create($this->year, 1, 1), CarbonImmutable::create($this->year, 12, 31));
    }

    /**
     * @return array<int, array{label: string, income: float, base: float, monthly: float, chargedMonths: int, total: float, paidIn: string}>
     */
    public function quarters(): array
    {
        $quarters = [];

        foreach (range(0, 3) as $index) {
            $start = CarbonImmutable::create($this->year, $index * 3 + 1, 1);
            $income = $this->incomeBetween($start, $start->addMonths(2)->endOfMonth());
            [$base, $monthly] = $this->contribution($income);

            $paymentMonths = collect(range(0, 2))->map(fn (int $offset) => $start->addMonths(3 + $offset));
            $charged = $paymentMonths->reject(fn (CarbonImmutable $month) => $this->isExemptMonth($month))->count();

            $quarters[] = [
                'label' => self::QUARTER_LABELS[$index],
                'income' => $income,
                'base' => $base,
                'monthly' => $monthly,
                'chargedMonths' => $charged,
                'total' => round($monthly * $charged, 2),
                'paidIn' => Period::SHORT_MONTHS[$paymentMonths->first()->month - 1].' – '.Period::SHORT_MONTHS[$paymentMonths->last()->month - 1].' '.$paymentMonths->last()->year,
            ];
        }

        return $quarters;
    }

    public function ssYearTotal(): float
    {
        return round(array_sum(array_column($this->quarters(), 'total')), 2);
    }

    /** Contribution due in a given month, from the income of the quarter before that month's quarter. */
    public function ssContributionForMonth(CarbonImmutable $month): float
    {
        $month = $month->startOfMonth();

        if ($this->isExemptMonth($month)) {
            return 0.0;
        }

        $quarterStart = $month->firstOfQuarter()->subMonths(3);
        [, $monthly] = $this->contribution($this->incomeBetween($quarterStart, $quarterStart->addMonths(2)->endOfMonth()));

        return $monthly;
    }

    public function isExemptMonth(CarbonImmutable $month): bool
    {
        $start = $this->settings->activityStartedOn();

        return $this->settings->ssFirstYearExempt()
            && $start !== null
            && $month->startOfMonth()->lt($start->addMonths(12)->startOfMonth());
    }

    public function firstYearEndsOn(): ?CarbonImmutable
    {
        return $this->settings->activityStartedOn()?->addMonths(12)->subDay();
    }

    public function irsExempt(): bool
    {
        return $this->settings->irsExempt();
    }

    public function irsTaxableIncome(): float
    {
        return $this->irsExempt() ? 0.0 : round($this->income() * self::IRS_SERVICES_COEFFICIENT, 2);
    }

    public function vatExempt(): bool
    {
        return $this->settings->vatExempt();
    }

    /** Payments received in the year but marked tax-exempt, left out of every figure above. */
    public function exemptIncome(): float
    {
        return round((float) Payment::query()
            ->whereBetween('paid_on', [CarbonImmutable::create($this->year, 1, 1)->startOfDay()->toDateTimeString(), CarbonImmutable::create($this->year, 12, 31)->endOfDay()->toDateTimeString()])
            ->where('tax_exempt', true)
            ->sum('amount'), 2);
    }

    public function vatThresholdPercent(): float
    {
        $threshold = $this->settings->vatExemptionThreshold();

        return $threshold > 0 ? round($this->income() / $threshold * 100, 1) : 0.0;
    }

    /** ok, warning (≥ 80%) or danger (≥ 100%). */
    public function vatThresholdStatus(): string
    {
        $percent = $this->vatThresholdPercent();

        return match (true) {
            $percent >= 100 => 'danger',
            $percent >= 80 => 'warning',
            default => 'ok',
        };
    }

    public function vatCollected(): float
    {
        return round($this->payments(CarbonImmutable::create($this->year, 1, 1), CarbonImmutable::create($this->year, 12, 31))
            ->sum(function (Payment $payment): float {
                $rate = (float) ($payment->receivable?->billable?->vat_rate ?? 0);

                return $rate > 0 ? (float) $payment->amount * $rate / (1 + $rate) : 0.0;
            }), 2);
    }

    public function vatPaid(): float
    {
        return round(Expense::query()
            ->where('is_paid', true)
            ->whereBetween('spent_on', Period::of($this->year)->range())
            ->get()
            ->sum(fn (Expense $expense) => $expense->vat_amount), 2);
    }

    public function vatBalance(): float
    {
        return round($this->vatCollected() - $this->vatPaid(), 2);
    }

    /** @return array{0: float, 1: float} [base, monthly contribution] */
    private function contribution(float $income): array
    {
        $base = $income * $this->settings->ssRelevantIncomeShare() / 3;

        return [round($base, 2), round($base * $this->settings->ssRate(), 2)];
    }

    private function incomeBetween(CarbonImmutable $from, CarbonImmutable $to): float
    {
        $key = $from->toDateString().'|'.$to->toDateString();

        return $this->incomeCache[$key] ??= round((float) Payment::query()
            ->whereBetween('paid_on', [$from->startOfDay()->toDateTimeString(), $to->endOfDay()->toDateTimeString()])
            ->where('tax_exempt', false)
            ->sum('amount'), 2);
    }

    /** @return Collection<int, Payment> */
    private function payments(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Payment::query()
            ->with('receivable.billable')
            ->whereBetween('paid_on', [$from->startOfDay()->toDateTimeString(), $to->endOfDay()->toDateTimeString()])
            ->where('tax_exempt', false)
            ->get();
    }
}
