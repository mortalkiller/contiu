<?php

namespace App\Support;

use App\Enums\ExpenseAttribution;
use App\Models\Expense;
use App\Models\FiscalProfile;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Rough tax picture of a freelancer in the simplified regime. ESTIMATES ONLY — confirm with an accountant.
 * The rules follow docs/fiscal/memoria-fiscal-ti-eni-portugal-2026.md (rule IDs in brackets).
 *
 * - Income: payments received without the VAT inside them, plus the IRS withheld by the client
 *   (the income is gross of withholding and never includes VAT: SS021).
 * - Segurança Social (trabalhador independente): each quarter's income is declared in the following
 *   month (Jan, Apr, Jul, Oct) and sets the contribution of that month's quarter: base = income ×
 *   relevant share (70 % services, 20 % goods: SS020) / 3 (SS030), plus the chosen adjustment
 *   (±25 %: SS032), never above 12 IAS (SS033); contribution = base × rate, never below 20 €
 *   (SS031). Months before the enrolment takes effect are not charged, and their income does not
 *   count (SS051), so the first contributions are the 20 € minimum until the first declaration
 *   (SS015). The contribution of a month is paid between the 10th and the 20th of the next one.
 *   The accumulation exemption (art. 157.º, SS040) ends for a quarter whose relevant income reaches
 *   4 IAS a month: the contribution is then on the excess, without the 20 € minimum, and not below
 *   5 € (SS042). Other recognised exemptions leave every month without contribution.
 * - IRS (art. 31.º and 12.º-B CIRS): IRS Jovem exempts a share of the gross income first (capped at
 *   55 × IAS), then the coefficient, reduced by 50 % / 25 % in the first two years of services
 *   (INI001), gives the taxable income; the contributions above 10 % of the gross are deducted
 *   (DES001) and the expenses missing to reach 15 % of the gross are added (DES002). The tax
 *   itself is not computed.
 * - IVA: art. 53.º exemption → progress against the yearly threshold; otherwise VAT collected vs paid.
 */
final class TaxEstimator
{
    public const QUARTER_LABELS = ['Jan – Mar', 'Apr – Jun', 'Jul – Sep', 'Oct – Dec'];

    /** IRS Jovem exempts at most this many times the year's IAS. */
    public const IRS_JOVEM_IAS_CAP = 55;

    /** Cents: the monthly contribution of a trabalhador independente never goes below it (SS031). */
    public const SS_MINIMUM_CONTRIBUTION = 2000;

    /** Cents: a contribution on the excess over 4 IAS below this is not due (SS042). */
    public const SS_MINIMUM_EXCESS_CONTRIBUTION = 500;

    /** The monthly base never goes above this many IAS (SS033). */
    public const SS_BASE_CAP_IAS = 12;

    /**
     * The accumulation exemption (art. 157.º, n.º 1, al. a)) holds while the relevant income stays
     * under this many IAS a month, on average over a quarter.
     */
    public const SS_ACCUMULATION_LIMIT_IAS = 4;

    /** @var array<string, int> */
    private array $incomeCache = [];

    /** @var array<int, Collection<int, array{paidOn: string, retained: int}>> */
    private array $withholdingByYear = [];

    /** @var array<string, int> */
    private array $vatCollectedCache = [];

    /** @var array<string, array{status: string, base: int, contribution: int}> */
    private array $ssMonths = [];

    private ?int $ssPaidInYear = null;

    /** @var array<int, Collection<int, FiscalProfile>> */
    private array $fiscalProfilesByAccount = [];

    public function __construct(private readonly FinanceSettings $settings, public readonly int $year) {}

    public function settings(): FinanceSettings
    {
        return $this->settings;
    }

    /**
     * Fields outside confirmed_fields use the current FinanceSettings.
     *
     * @return array{profile_id: ?int, effective_from: ?string, confirmed_fields: list<string>}
     */
    public function fiscalBasisOn(?CarbonImmutable $date = null): array
    {
        $profile = $this->fiscalProfileOn($date ?? $this->annualReferenceDate());

        return [
            'profile_id' => $profile?->id,
            'effective_from' => $profile?->effective_from->toDateString(),
            'confirmed_fields' => $profile?->confirmedEstimateFields() ?? [],
        ];
    }

    /** @return Collection<int, FiscalProfile> */
    private function fiscalProfiles(): Collection
    {
        return $this->fiscalProfilesByAccount[CurrentAccount::id()] ??= FiscalProfile::query()
            ->inEffectiveOrder()
            ->get(['id', 'effective_from', ...FiscalProfile::ESTIMATE_FIELDS]);
    }

    private function fiscalProfileOn(CarbonImmutable $date): ?FiscalProfile
    {
        $day = $date->toDateString();

        return $this->fiscalProfiles()->first(fn (FiscalProfile $profile): bool => $profile->effective_from->toDateString() <= $day);
    }

    private function annualReferenceDate(): CarbonImmutable
    {
        return $this->year === today()->year ? CarbonImmutable::today() : CarbonImmutable::create($this->year, 12, 31);
    }

    private function activityStartedOn(CarbonImmutable $date): ?CarbonImmutable
    {
        return $this->fiscalProfileOn($date)?->activity_started_on ?? $this->settings->activityStartedOn();
    }

    /** Income between two dates, inclusive (cents): see incomeBetween(). */
    public function incomeIn(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return $this->incomeBetween($from->startOfDay(), $to->startOfDay());
    }

    public function income(): int
    {
        return $this->incomeBetween(CarbonImmutable::create($this->year, 1, 1), CarbonImmutable::create($this->year, 12, 31));
    }

    /**
     * Per quarter of income: the contributions it sets (the next quarter's months, `chargedMonths` of
     * them charged), paid each the month after (`paidIn`: Q3 → contributions Oct–Dec → paid Nov–Jan).
     * `base` and `monthly` are the ones of the first charged month (or the first month), `status`
     * the one of the first of those contributions (charged, first_enrolment or exempt).
     *
     * @return array<int, array{label: string, income: int, base: int, monthly: int, chargedMonths: int, total: int, paidIn: string, status: string} (cents)>
     */
    public function quarters(): array
    {
        $quarters = [];

        foreach (range(0, 3) as $index) {
            $start = CarbonImmutable::create($this->year, $index * 3 + 1, 1);
            $months = collect(range(0, 2))->map(fn (int $offset): array => $this->ssMonth($start->addMonths(3 + $offset)));
            $charged = $months->where('status', 'charged');
            $shown = $charged->first() ?? $months->first();
            $firstPayment = $start->addMonths(4);
            $lastPayment = $start->addMonths(6);

            $quarters[] = [
                'label' => __(self::QUARTER_LABELS[$index]),
                'income' => $this->incomeBetween($start, $start->addMonths(2)->endOfMonth()),
                'base' => $shown['base'],
                'monthly' => $shown['contribution'],
                'chargedMonths' => $charged->count(),
                'total' => (int) $charged->sum('contribution'),
                'paidIn' => Period::monthName($firstPayment->month, short: true)
                    .($firstPayment->year === $lastPayment->year ? '' : ' '.$firstPayment->year)
                    .' – '.Period::monthName($lastPayment->month, short: true).' '.$lastPayment->year,
                'status' => $months->first()['status'],
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
        return $this->ssMonth($month)['contribution'];
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
     * Contributions paid in the year (January pays December's): the amount the Social Security
     * confirmed when there is one, else the estimate. For the IRS deductions (DES001, DES003).
     */
    public function ssPaidInYear(): int
    {
        $confirmed = app(SocialSecurity::class);

        return $this->ssPaidInYear ??= (int) collect(range(1, 12))->sum(function (int $month) use ($confirmed): int {
            $date = CarbonImmutable::create($this->year, $month, 1);

            return $confirmed->confirmedPaymentInMonth($date) ?? $this->ssPaymentInMonth($date);
        });
    }

    /**
     * First month with a contribution (paid the month after): the SS enrolment date when the account has it, else computed
     * from the activity start (a first start when the kind is not said yet). Null without a start
     * date: every month is charged.
     */
    public function ssObligationStartsOn(?CarbonImmutable $date = null): ?CarbonImmutable
    {
        if ($enrolled = $this->settings->ssEnrolledFrom()) {
            return $enrolled->startOfMonth();
        }

        $start = $this->activityStartedOn($date ?? $this->annualReferenceDate())?->startOfMonth();

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

    /**
     * Of a month's contribution: charged | first_enrolment (before the obligation starts) | exempt
     * (art. 157.º). An accumulation exemption is charged again, on the excess, in the months whose
     * declared quarter reached 4 IAS a month (SS042).
     */
    public function ssMonthStatus(CarbonImmutable $month): string
    {
        return $this->ssMonth($month)['status'];
    }

    /**
     * Status, base and contribution of a month (cents). The base comes from the quarter declared in
     * the month's quarter (the quarter before), the caps from the IAS of the month's year.
     *
     * @return array{status: string, base: int, contribution: int}
     */
    public function ssMonth(CarbonImmutable $month): array
    {
        $month = $month->startOfMonth();

        return $this->ssMonths[$month->format('Y-m')] ??= $this->computeSsMonth($month);
    }

    /** @return array{status: string, base: int, contribution: int} */
    private function computeSsMonth(CarbonImmutable $month): array
    {
        $startsOn = $this->ssObligationStartsOn($month);
        $beforeStart = $startsOn !== null && $month->lt($startsOn);
        $relevant = $this->ssRelevantMonthlyIncome($month->firstOfQuarter()->subMonths(3));
        $ias = YearlyParameter::ias($month->year);
        $cap = self::SS_BASE_CAP_IAS * $ias;

        if ($this->fiscalProfileOn($month)?->ss_contribution_exempt ?? $this->settings->ssContributionExempt()) {
            $excess = $relevant - self::SS_ACCUMULATION_LIMIT_IAS * $ias;

            if ($this->settings->ssExemptionReason() !== 'accumulation' || $excess < 0 || $beforeStart) {
                return ['status' => 'exempt', 'base' => 0, 'contribution' => 0];
            }

            $base = min($excess, $cap);
            $contribution = Money::share($base, $this->settings->ssRate());

            return ['status' => 'charged', 'base' => $base, 'contribution' => $contribution < self::SS_MINIMUM_EXCESS_CONTRIBUTION ? 0 : $contribution];
        }

        if ($beforeStart) {
            return ['status' => 'first_enrolment', 'base' => 0, 'contribution' => 0];
        }

        $base = min(Money::round($relevant * (1 + $this->settings->ssBaseAdjustment())), $cap);

        return ['status' => 'charged', 'base' => $base, 'contribution' => max(Money::share($base, $this->settings->ssRate()), self::SS_MINIMUM_CONTRIBUTION)];
    }

    /** Indexante dos Apoios Sociais of the year in cents; the closest known year when missing. */
    public function ias(): int
    {
        return YearlyParameter::ias($this->year);
    }

    /** Cents a month: 4 × IAS of the year. */
    public function ssAccumulationLimit(): int
    {
        return self::SS_ACCUMULATION_LIMIT_IAS * $this->ias();
    }

    /**
     * Relevant income per month of the quarter starting on $quarterStart (cents): income × share ÷ 3
     * (SS020, SS030), counting only the months from the enrolment's start (SS051).
     */
    public function ssRelevantMonthlyIncome(CarbonImmutable $quarterStart): int
    {
        $from = $quarterStart->startOfMonth();
        $to = $from->addMonths(2)->endOfMonth();
        $startsOn = $this->ssObligationStartsOn($to);

        if ($startsOn !== null && $startsOn->gt($from)) {
            $from = $startsOn;
        }

        $income = $from->gt($to) ? 0 : $this->incomeBetween($from, $to);

        return Money::round($income * $this->settings->ssRelevantIncomeShare() / 3);
    }

    public function iasIsProvisional(): bool
    {
        return YearlyParameter::isProvisional('ias', $this->year);
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

            if ($ended === null || $ended->addYears(5)->gt($this->activityStartedOn($this->annualReferenceDate()))) {
                return 'recent_restart';
            }
        }

        return null;
    }

    /**
     * 50 % in the start year, 25 % in the next, before any blocker. Only for services: sales of
     * goods, restaurants and hotels (the 0.15 coefficient) never get it (INI001).
     */
    private function irsEarlyYearReduction(): float
    {
        if ($this->settings->sellsGoodsUnderIrs()) {
            return 0.0;
        }

        $start = $this->activityStartedOn($this->annualReferenceDate());

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

    /**
     * (gross − IRS Jovem) × coefficient − contributions above 10 % + expenses missing to reach 15 %,
     * in cents, never below zero. The shortfall is left out while it cannot be worked out
     * (irsExpenseShortfall() null).
     */
    public function irsTaxableIncome(): int
    {
        $afterCoefficient = Money::share($this->income() - $this->irsJovemExempt(), $this->effectiveIrsCoefficient());

        return max(0, $afterCoefficient - $this->irsContributionsDeduction()) + ($this->irsExpenseShortfall() ?? 0);
    }

    /**
     * Art. 31.º, n.º 2, CIRS (DES001): the compulsory contributions paid in the year, in the part
     * above 10 % of the gross income of services. Not for sales of goods (0.15 coefficient).
     */
    public function irsContributionsDeduction(): int
    {
        if ($this->settings->sellsGoodsUnderIrs()) {
            return 0;
        }

        return max(0, $this->ssPaidInYear() - Money::share($this->income(), 0.10));
    }

    /**
     * Art. 31.º, n.º 13, CIRS (DES002): 15 % of the gross income of services must be backed by
     * expenses; what is missing is added to the taxable income. The automatic part is the
     * art. 25.º deduction, or the contributions not deducted above when larger (DES003). Null with
     * IRS Jovem: how the two combine is not confirmed (JOV008), so no figure is shown.
     */
    public function irsExpenseShortfall(): ?int
    {
        if ($this->settings->sellsGoodsUnderIrs()) {
            return 0;
        }

        if ($this->irsJovemPercent() > 0) {
            return null;
        }

        $gross = $this->income();
        $automatic = max(YearlyParameter::irsSpecificDeduction($this->year), min($this->ssPaidInYear(), Money::share($gross, 0.10)));

        return max(0, Money::share($gross, 0.15) - $automatic - $this->irsEligibleExpenses());
    }

    /**
     * Expenses of the year that count for the 15 % (art. 31.º, n.º 13, DES004, DES005): those of
     * business use in full, those of mixed use at 25 %. With the VAT exemption the VAT is a cost
     * and counts; otherwise only the amount without VAT.
     */
    public function irsEligibleExpenses(): int
    {
        $vatIsCost = $this->vatExempt();

        return (int) Expense::query()
            ->whereBetween('spent_on', [CarbonImmutable::create($this->year, 1, 1)->toDateString(), CarbonImmutable::create($this->year, 12, 31)->toDateString()])
            ->get()
            ->sum(function (Expense $expense) use ($vatIsCost): int {
                $amount = $vatIsCost ? $expense->total : (int) $expense->net_amount;

                return $expense->attribution === ExpenseAttribution::Mixed ? Money::share($amount, 0.25) : $amount;
            });
    }

    public function vatExempt(?CarbonImmutable $date = null): bool
    {
        return $this->fiscalProfileOn($date ?? $this->annualReferenceDate())?->vat_exempt ?? $this->settings->vatExempt();
    }

    /** Annual threshold in cents for the version in force on the given date. */
    public function vatExemptionThreshold(?CarbonImmutable $date = null): int
    {
        return $this->fiscalProfileOn($date ?? $this->annualReferenceDate())?->vat_exemption_threshold
            ?? $this->settings->vatExemptionThreshold();
    }

    /**
     * Calendar-year intervals, inclusive, with the estimate and source for each version.
     * Exemption progress uses the annual receipts proxy up to the end of the interval.
     *
     * @return list<array{from: string, to: string, exempt: bool, threshold: int, thresholdPercent: ?float,
     *     collected: ?int, paid: ?int, balance: ?int, basis: array{profile_id: ?int, effective_from: ?string, confirmed_fields: list<string>}}>
     */
    public function vatPeriods(): array
    {
        $start = CarbonImmutable::create($this->year, 1, 1);
        $end = $start->endOfYear();
        $starts = $this->fiscalProfiles()
            ->pluck('effective_from')
            ->filter(fn (CarbonImmutable $date): bool => $date->gt($start) && $date->lte($end))
            ->unique(fn (CarbonImmutable $date): string => $date->toDateString())
            ->reverse()
            ->prepend($start)
            ->values();

        return $starts->map(function (CarbonImmutable $from, int $index) use ($starts, $start, $end): array {
            $to = $starts->get($index + 1)?->subDay() ?? $end;
            $exempt = $this->vatExempt($from);
            $threshold = $this->vatExemptionThreshold($from);
            $collected = $exempt ? null : $this->vatCollectedBetween($from, $to);
            $paid = $exempt ? null : $this->vatPaidBetween($from, $to);

            return [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'exempt' => $exempt,
                'threshold' => $threshold,
                'thresholdPercent' => $exempt ? $this->vatThresholdProgress($this->incomeBetween($start, $to), $threshold) : null,
                'collected' => $collected,
                'paid' => $paid,
                'balance' => $exempt ? null : $collected - $paid,
                'basis' => $this->fiscalBasisOn($from),
            ];
        })->all();
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
        return $this->vatThresholdProgress($this->income(), $this->vatExemptionThreshold());
    }

    private function vatThresholdProgress(int $income, int $threshold): float
    {
        return $threshold > 0 ? round($income / $threshold * 100, 1) : 0.0;
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
        return $this->vatCollectedBetween(CarbonImmutable::create($this->year, 1, 1), CarbonImmutable::create($this->year, 12, 31));
    }

    private function vatCollectedBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return $this->vatCollectedCache[$from->toDateString().'|'.$to->toDateString()] ??= (int) $this->payments($from, $to)
            ->sum(function (Payment $payment): int {
                return $payment->allocations->sum(function ($allocation): int {
                    $rate = (string) ($allocation->receivable->billable?->vat_rate ?? 0);

                    return (float) $rate > 0 ? $allocation->amount - Money::netFromGross($allocation->amount, $rate) : 0;
                });
            });
    }

    public function vatPaid(): int
    {
        return $this->vatPaidBetween(CarbonImmutable::create($this->year, 1, 1), CarbonImmutable::create($this->year, 12, 31));
    }

    private function vatPaidBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) Expense::query()
            ->where('is_paid', true)
            ->whereBetween('spent_on', [$from->startOfDay()->toDateTimeString(), $to->endOfDay()->toDateTimeString()])
            ->get()
            ->sum(fn (Expense $expense) => $expense->vat_amount);
    }

    public function vatBalance(): int
    {
        return $this->vatCollected() - $this->vatPaid();
    }

    /**
     * Income of the period (cents): the payments received, without the VAT inside them, plus the
     * IRS the clients withheld on them (SS021; the income is gross of withholding).
     */
    private function incomeBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $key = $from->toDateString().'|'.$to->toDateString();

        if (! isset($this->incomeCache[$key])) {
            $received = (int) Payment::query()
                ->whereBetween('paid_on', [$from->startOfDay()->toDateTimeString(), $to->endOfDay()->toDateTimeString()])
                ->where('tax_exempt', false)
                ->sum('amount');

            $this->incomeCache[$key] = $received === 0 ? $this->withheldBetween($from, $to)
                : $received - $this->vatCollectedBetween($from, $to) + $this->withheldBetween($from, $to);
        }

        return $this->incomeCache[$key];
    }

    /** IRS withheld by clients on the payments of the period, as the withholding review confirms it. */
    private function withheldBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $total = 0;

        foreach (range($from->year, $to->year) as $year) {
            $total += (int) ($this->withholdingByYear[$year] ??= WithholdingReview::forYear($year)->rows
                ->where('current', true)
                ->map(fn (array $row): array => ['paidOn' => $row['record']->paid_on->toDateString(), 'retained' => (int) $row['record']->retained_amount])
                ->values())
                ->filter(fn (array $row): bool => $row['paidOn'] >= $from->toDateString() && $row['paidOn'] <= $to->toDateString())
                ->sum('retained');
        }

        return $total;
    }

    /** @return Collection<int, Payment> */
    private function payments(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Payment::query()
            ->with('allocations.receivable.billable')
            ->whereBetween('paid_on', [$from->startOfDay()->toDateTimeString(), $to->endOfDay()->toDateTimeString()])
            ->where('tax_exempt', false)
            ->get();
    }
}
