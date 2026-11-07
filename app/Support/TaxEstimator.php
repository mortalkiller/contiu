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

    /** @var array<string, int> */
    private array $incomeCache = [];

    public function __construct(private readonly FinanceSettings $settings, public readonly int $year) {}

    public function settings(): FinanceSettings
    {
        return $this->settings;
    }

    public function income(): int
    {
        return $this->incomeBetween(CarbonImmutable::create($this->year, 1, 1), CarbonImmutable::create($this->year, 12, 31));
    }

    /**
     * @return array<int, array{label: string, income: int, base: int, monthly: int, chargedMonths: int, total: int, paidIn: string} (cents)>
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
                'total' => $monthly * $charged,
                'paidIn' => Period::SHORT_MONTHS[$paymentMonths->first()->month - 1].' – '.Period::SHORT_MONTHS[$paymentMonths->last()->month - 1].' '.$paymentMonths->last()->year,
            ];
        }

        return $quarters;
    }

    public function ssYearTotal(): int
    {
        return (int) array_sum(array_column($this->quarters(), 'total'));
    }

    /** Contribution due in a given month, from the income of the quarter before that month's quarter. */
    public function ssContributionForMonth(CarbonImmutable $month): int
    {
        $month = $month->startOfMonth();

        if ($this->isExemptMonth($month)) {
            return 0;
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

    public function irsTaxableIncome(): int
    {
        return $this->irsExempt() ? 0 : Money::share($this->income(), self::IRS_SERVICES_COEFFICIENT);
    }

    public function vatExempt(): bool
    {
        return $this->settings->vatExempt();
    }

    /** Payments received in the year but marked tax-exempt, left out of every figure above. */
    public function exemptIncome(): int
    {
        return (int) Payment::query()
            ->whereBetween('paid_on', [CarbonImmutable::create($this->year, 1, 1)->startOfDay()->toDateTimeString(), CarbonImmutable::create($this->year, 12, 31)->endOfDay()->toDateTimeString()])
            ->where('tax_exempt', true)
            ->sum('amount');
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

    /** VAT inside the payments received (payment − its net), per payment to the cent. */
    public function vatCollected(): int
    {
        return (int) $this->payments(CarbonImmutable::create($this->year, 1, 1), CarbonImmutable::create($this->year, 12, 31))
            ->sum(function (Payment $payment): int {
                $rate = (string) ($payment->receivable?->billable?->vat_rate ?? 0);

                return (float) $rate > 0 ? $payment->amount - Money::netFromGross($payment->amount, $rate) : 0;
            });
    }

    public function vatPaid(): int
    {
        return (int) Expense::query()
            ->where('is_paid', true)
            ->whereBetween('spent_on', Period::of($this->year)->range())
            ->get()
            ->sum(fn (Expense $expense) => $expense->vat_amount);
    }

    public function vatBalance(): int
    {
        return $this->vatCollected() - $this->vatPaid();
    }

    /** @return array{0: int, 1: int} [base, monthly contribution] in cents */
    private function contribution(int $income): array
    {
        $base = Money::round($income * $this->settings->ssRelevantIncomeShare() / 3);

        return [$base, Money::share($base, $this->settings->ssRate())];
    }

    private function incomeBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $key = $from->toDateString().'|'.$to->toDateString();

        return $this->incomeCache[$key] ??= (int) Payment::query()
            ->whereBetween('paid_on', [$from->startOfDay()->toDateTimeString(), $to->endOfDay()->toDateTimeString()])
            ->where('tax_exempt', false)
            ->sum('amount');
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
