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
 *   month (Jan, Apr, Jul, Oct) and sets the contribution of that month's quarter: base = income ×
 *   relevant share / 3, contribution = base × rate, never below the 20 € minimum (art. 163.º), also
 *   in quarters without income. The contribution of a month is paid between the 10th and the 20th of
 *   the next month (art. 155.º): October's, from jul–set, is paid in November. The obligation starts
 *   on the SS enrolment date, or else 12 months after a first start (art. 145.º, n.º 1) or on a
 *   restart (n.º 3); months before it are "1.º enquadramento", so the first payment is the month
 *   after the start. A recognised exemption (art. 157.º) leaves every month without contribution.
 * - IRS (art. 31.º and 12.º-B CIRS): IRS Jovem exempts a share of the gross income first (capped at
 *   55 × IAS), then the configured coefficient, reduced by 50 % / 25 % in the first two years of
 *   activity, gives the taxable income. The tax itself is not computed.
 * - IVA: art. 53.º exemption → progress against the yearly threshold; otherwise VAT collected vs paid.
 */
final class TaxEstimator
{
    public const QUARTER_LABELS = ['Jan – Mar', 'Abr – Jun', 'Jul – Set', 'Out – Dez'];

    /** IRS Jovem exempts at most this many times the year's IAS. */
    public const IRS_JOVEM_IAS_CAP = 55;

    /** Cents: the monthly contribution of a trabalhador independente never goes below it. */
    public const SS_MINIMUM_CONTRIBUTION = 2000;

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
     * Per quarter of income: the contributions it sets (the next quarter's months, `chargedMonths` of
     * them charged), paid each the month after (`paidIn`: Q3 → contributions Oct–Dec → paid Nov–Jan).
     * `status` is the one of the first of those contributions (charged, first_enrolment or exempt).
     *
     * @return array<int, array{label: string, income: int, base: int, monthly: int, chargedMonths: int, total: int, paidIn: string, status: string} (cents)>
     */
    public function quarters(): array
    {
        $quarters = [];

        foreach (range(0, 3) as $index) {
            $start = CarbonImmutable::create($this->year, $index * 3 + 1, 1);
            $income = $this->incomeBetween($start, $start->addMonths(2)->endOfMonth());
            [$base, $monthly] = $this->contribution($income);

            $contributionMonths = collect(range(0, 2))->map(fn (int $offset) => $start->addMonths(3 + $offset));
            $charged = $contributionMonths->filter(fn (CarbonImmutable $month) => $this->ssMonthStatus($month) === 'charged')->count();
            $firstPayment = $contributionMonths->first()->addMonth();
            $lastPayment = $contributionMonths->last()->addMonth();

            $quarters[] = [
                'label' => self::QUARTER_LABELS[$index],
                'income' => $income,
                'base' => $base,
                'monthly' => $monthly,
                'chargedMonths' => $charged,
                'total' => $monthly * $charged,
                'paidIn' => Period::SHORT_MONTHS[$firstPayment->month - 1]
                    .($firstPayment->year === $lastPayment->year ? '' : ' '.$firstPayment->year)
                    .' – '.Period::SHORT_MONTHS[$lastPayment->month - 1].' '.$lastPayment->year,
                'status' => $this->ssMonthStatus($contributionMonths->first()),
            ];
        }

        return $quarters;
    }

    public function ssYearTotal(): int
    {
        return (int) array_sum(array_column($this->quarters(), 'total'));
    }

    /**
     * Contribution OF a month (not the one paid in it), from the income of the quarter before that
     * month's quarter. It is paid the month after: see ssPaymentInMonth().
     */
    public function ssContributionForMonth(CarbonImmutable $month): int
    {
        $month = $month->startOfMonth();

        if ($this->ssMonthStatus($month) !== 'charged') {
            return 0;
        }

        $quarterStart = $month->firstOfQuarter()->subMonths(3);
        [, $monthly] = $this->contribution($this->incomeBetween($quarterStart, $quarterStart->addMonths(2)->endOfMonth()));

        return $monthly;
    }

    /** Paid between the 10th and the 20th of a month: the contribution of the month before. */
    public function ssPaymentInMonth(CarbonImmutable $month): int
    {
        return $this->ssContributionForMonth($month->startOfMonth()->subMonth());
    }

    /** Status of what is paid in a month: the one of the month before's contribution. */
    public function ssPaymentStatus(CarbonImmutable $month): string
    {
        return $this->ssMonthStatus($month->startOfMonth()->subMonth());
    }

    /**
     * First month with a contribution (paid the month after): the SS enrolment date when the account has it, else computed
     * from the activity start (a first start when the kind is not said yet). Null without a start
     * date: every month is charged.
     */
    public function ssObligationStartsOn(): ?CarbonImmutable
    {
        if ($enrolled = $this->settings->ssEnrolledFrom()) {
            return $enrolled->startOfMonth();
        }

        $start = $this->settings->activityStartedOn()?->startOfMonth();

        return match ($this->settings->activityStartKind()) {
            'restart' => $start,
            default => $start?->addMonths(12),
        };
    }

    /** The obligation start is the app's calculation, not the date the SS gave. */
    public function ssObligationIsEstimated(): bool
    {
        return $this->settings->ssEnrolledFrom() === null && $this->ssObligationStartsOn() !== null;
    }

    /** Of a month's contribution: charged | first_enrolment (before the obligation starts) | exempt (art. 157.º). */
    public function ssMonthStatus(CarbonImmutable $month): string
    {
        if ($this->settings->ssContributionExempt()) {
            return 'exempt';
        }

        $startsOn = $this->ssObligationStartsOn();

        return $startsOn !== null && $month->startOfMonth()->lt($startsOn) ? 'first_enrolment' : 'charged';
    }

    /** Indexante dos Apoios Sociais of the year in cents; the closest known year when missing. */
    public function ias(): int
    {
        $table = config('contiu.ias');

        if (isset($table[$this->year])) {
            return (int) $table[$this->year];
        }

        $known = array_keys($table);
        $earlier = array_filter($known, fn (int $year) => $year < $this->year);

        return (int) $table[$earlier === [] ? min($known) : max($earlier)];
    }

    /** The year has no IAS in the table yet: the cap uses another year's. */
    public function iasIsProvisional(): bool
    {
        return ! isset(config('contiu.ias')[$this->year]);
    }

    /**
     * Share of the gross income exempt by IRS Jovem (art. 12.º-B): by the year number, while 35 or
     * younger on 31/12. Without a birth date the age is not checked.
     */
    public function irsJovemPercent(): float
    {
        $number = $this->settings->irsJovemYearNumber($this->year);
        $birth = $this->settings->birthDate();

        if (! $this->settings->irsJovem() || $number === null
            || ($birth !== null && $birth->addYears(36)->lte(CarbonImmutable::create($this->year, 12, 31)))) {
            return 0.0;
        }

        return match (true) {
            $number === 1 => 1.0,
            $number >= 2 && $number <= 4 => 0.75,
            $number >= 5 && $number <= 7 => 0.5,
            $number >= 8 && $number <= 10 => 0.25,
            default => 0.0,
        };
    }

    /** Cents exempt by IRS Jovem, never above 55 × IAS. */
    public function irsJovemExempt(): int
    {
        return min(Money::share($this->income(), $this->irsJovemPercent()), self::IRS_JOVEM_IAS_CAP * $this->ias());
    }

    /**
     * Reduction of the coefficient (art. 31.º, n.os 10 and 11): 50 % in the year the activity starts,
     * 25 % in the next, unless blocked (irsCoefficientReductionBlocker()).
     */
    public function irsCoefficientReduction(): float
    {
        if ($this->irsCoefficientReductionBlocker() !== null) {
            return 0.0;
        }

        return $this->irsEarlyYearReduction();
    }

    /**
     * Why an early year (the start year or the next) gets no reduction: dependent_income (a year with
     * salary or pension) or recent_restart (a restart less than 5 years after the previous cessation,
     * or with that date unknown). Null outside the early years or when the reduction applies.
     */
    public function irsCoefficientReductionBlocker(): ?string
    {
        if ($this->irsEarlyYearReduction() === 0.0) {
            return null;
        }

        if (in_array($this->year, $this->settings->dependentIncomeYears(), true)) {
            return 'dependent_income';
        }

        if ($this->settings->activityStartKind() === 'restart') {
            $ended = $this->settings->previousActivityEndedOn();

            if ($ended === null || $ended->addYears(5)->gt($this->settings->activityStartedOn())) {
                return 'recent_restart';
            }
        }

        return null;
    }

    /** 50 % in the start year, 25 % in the next, before any blocker. */
    private function irsEarlyYearReduction(): float
    {
        $start = $this->settings->activityStartedOn();

        return match ($start === null ? null : $this->year - $start->year) {
            0 => 0.5,
            1 => 0.25,
            default => 0.0,
        };
    }

    public function effectiveIrsCoefficient(): float
    {
        return round($this->settings->irsCoefficient() * (1 - $this->irsCoefficientReduction()), 6);
    }

    /** (gross − IRS Jovem) × coefficient, in cents. */
    public function irsTaxableIncome(): int
    {
        return Money::share($this->income() - $this->irsJovemExempt(), $this->effectiveIrsCoefficient());
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

    /** ok, warning (≥ 80%), danger (≥ 100%) or critical (≥ 125%). */
    public function vatThresholdStatus(): string
    {
        $percent = $this->vatThresholdPercent();

        return match (true) {
            $percent >= 125 => 'critical',
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

    /** @return array{0: int, 1: int} [base, monthly contribution with the minimum] in cents */
    private function contribution(int $income): array
    {
        $base = Money::round($income * $this->settings->ssRelevantIncomeShare() / 3);

        return [$base, max(Money::share($base, $this->settings->ssRate()), self::SS_MINIMUM_CONTRIBUTION)];
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
