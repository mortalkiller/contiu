<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Number;
use Throwable;

/**
 * Turns the sign-up answers, asked in plain language, into the fiscal settings (FinanceSettings
 * keys) and, for each value, the reason in words and where the user confirms it. The rules are
 * those of docs/fiscal/memoria-fiscal-ti-eni-portugal-2026.md, named by ID on each method. What
 * is deduced is a proposal: the estimate only takes it as a fact once the user confirms the
 * fiscal profile (ConfirmFiscalProfile).
 *
 * Every answer is optional, since the wizard fills them in step by step:
 * activity_opened (yes|no), accounting_regime (simplified|organised|unknown), activity_started_on,
 * activity_start_kind (first|restart), previous_activity_ended_on, ss_enrolled_from, situation
 * (list of employment|pension|self_only), employer_unrelated, salary_at_least_ias (yes|no),
 * employment_scheme (ss|cga), birth_date, irs_jovem (yes|no), irs_jovem_year_number, work_kind
 * (listed|other|goods), profession, commercial_only (yes|no), expected_turnover (cents) and
 * vat_answer (exempt|normal|unknown).
 */
final class TaxProfileDeduction
{
    /** Worked out from facts the user gave. */
    public const DEDUCED = 'deduced';

    /** Likely, but only the Finanças or the Segurança Social can say: the user confirms it. */
    public const PROBABLE = 'probable';

    /** Coefficient of each kind of work (art. 31.º, n.º 1, CIRS: SIM002, SIM003, SIM001). */
    public const COEFFICIENTS = ['listed' => 0.75, 'other' => 0.35, 'goods' => 0.15];

    /** General rate of the self-employed, and of sole traders with only commercial or industrial activity (SS003). */
    public const SS_RATE = 0.214;

    public const SS_RATE_SOLE_TRADER = 0.252;

    /** Simplified regime up to this yearly turnover, in cents (REG001). */
    public const SIMPLIFIED_REGIME_LIMIT = 20000000;

    /** IRS Jovem, up to this age on 31 December (JOV004). */
    public const IRS_JOVEM_MAX_AGE = 35;

    /** @param array<string, mixed> $answers */
    private function __construct(private readonly array $answers, private readonly CarbonImmutable $today) {}

    /** @param array<string, mixed> $answers */
    public static function from(array $answers, ?CarbonImmutable $today = null): self
    {
        return new self($answers, ($today ?? CarbonImmutable::today())->startOfDay());
    }

    public function year(): int
    {
        return $this->today->year;
    }

    /** waiting (activity not opened yet) | organised (organised accounting) | simplified. */
    public function path(): string
    {
        return match (true) {
            $this->yes('activity_opened') === false => 'waiting',
            $this->answer('accounting_regime') === 'organised' => 'organised',
            default => 'simplified',
        };
    }

    /**
     * The FinanceSettings values. A waiting account only says the activity is not open: the rest
     * is asked when it opens. Organised accounting keeps only the activity and VAT.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        if ($this->path() === 'waiting') {
            return ['activity_opened' => false];
        }

        $restart = $this->isRestart();
        $settings = [
            'activity_opened' => true,
            'accounting_regime' => $this->path() === 'organised' ? 'organised' : 'simplified',
            'activity_started_on' => $this->date('activity_started_on')?->toDateString(),
            'activity_start_kind' => $restart ? 'restart' : 'first',
            'previous_activity_ended_on' => $restart ? $this->date('previous_activity_ended_on')?->toDateString() : null,
            'ss_enrolled_from' => $restart ? $this->date('ss_enrolled_from')?->toDateString() : null,
            'vat_exempt' => (bool) $this->vatExempt(),
            'vat_exemption_threshold' => YearlyParameter::vatExemptionLimit($this->year()),
            'cae' => filled($this->answer('cae')) ? (string) $this->answer('cae') : null,
        ];

        if ($this->path() === 'organised') {
            return $settings;
        }

        $irsJovemNumber = $this->irsJovemPercent() !== null ? (int) $this->answer('irs_jovem_year_number') : null;

        return [
            ...$settings,
            'dependent_income_years' => $this->hasDependentIncome() ? [$this->year()] : [],
            'irs_coefficient' => $this->irsCoefficient() ?? self::COEFFICIENTS['listed'],
            'sells_goods' => $this->answer('work_kind') === 'goods',
            'ss_rate' => $this->ssRate(),
            'ss_contribution_exempt' => $this->ssExemptionReason() !== null,
            'ss_exemption_reason' => $this->ssExemptionReason(),
            'irs_jovem' => $irsJovemNumber !== null,
            'birth_date' => $this->date('birth_date')?->toDateString(),
            'irs_jovem_year' => $irsJovemNumber !== null ? ['year' => $this->year(), 'number' => $irsJovemNumber] : null,
        ];
    }

    /** @return list<string> employment | pension | self_only */
    public function situation(): array
    {
        $situation = $this->answers['situation'] ?? [];

        return array_values(array_intersect(['employment', 'pension', 'self_only'], is_array($situation) ? $situation : [$situation]));
    }

    /** A salary or a pension this year: blocks the first-years IRS reduction (INI001). */
    public function hasDependentIncome(): bool
    {
        return array_intersect(['employment', 'pension'], $this->situation()) !== [];
    }

    /**
     * When the Segurança Social starts counting: the first day of the 12th month after the month
     * the activity first started (SS011), or the date it shows after a restart (SS012).
     */
    public function ssStartsOn(): ?CarbonImmutable
    {
        if ($this->isRestart()) {
            return $this->date('ss_enrolled_from')?->startOfMonth();
        }

        return $this->date('activity_started_on')?->startOfMonth()->addMonths(12);
    }

    /**
     * Why the contributions are probably exempt (art. 157.º CRC): accumulation with a job for an
     * unrelated employer paying at least 1 IAS into the Segurança Social (SS040; with the CGA it
     * has to be requested, SS041), or an old-age or disability pension (SS043). Null: they are due.
     */
    public function ssExemptionReason(): ?string
    {
        $situation = $this->situation();

        return match (true) {
            in_array('employment', $situation, true) && $this->employmentBlocker() === null => 'accumulation',
            in_array('pension', $situation, true) => 'pension',
            default => null,
        };
    }

    /**
     * Why a job gives no exemption: same_employer (the employer is linked to the clients: SS006),
     * low_salary (under 1 IAS a month: SS040), cga (only on request: SS041). Null when it does,
     * or when the answers are not there yet.
     */
    public function employmentBlocker(): ?string
    {
        if (! in_array('employment', $this->situation(), true)) {
            return null;
        }

        return match (true) {
            $this->yes('employer_unrelated') === false => 'same_employer',
            $this->yes('salary_at_least_ias') === false => 'low_salary',
            $this->answer('employment_scheme') === 'cga' => 'cga',
            default => null,
        };
    }

    /**
     * Relevant income a month, in cents, from the expected turnover until the end of the year:
     * 70 % of services, 20 % of sales of goods (SS020), spread over the months of activity in the
     * year. Null without the turnover.
     */
    public function relevantMonthlyIncome(): ?int
    {
        $turnover = $this->turnover();

        if ($turnover === null) {
            return null;
        }

        $start = $this->date('activity_started_on');
        $months = $start !== null && $start->year === $this->year() ? 13 - $start->month : 12;
        $share = $this->answer('work_kind') === 'goods' ? 20 : 70;

        return (int) round($turnover * $share / (100 * $months));
    }

    /** 4 IAS a month of the year, in cents (SS040). */
    public function accumulationLimit(): int
    {
        return TaxEstimator::SS_ACCUMULATION_LIMIT_IAS * YearlyParameter::ias($this->year());
    }

    /**
     * Exempt by accumulation, but the relevant income reaches 4 IAS a month: contributions on the
     * excess, and the quarterly declaration is due (SS042: exactly 4 IAS already counts).
     */
    public function ssPaysOnExcess(): bool
    {
        $relevant = $this->relevantMonthlyIncome();

        return $this->ssExemptionReason() === 'accumulation' && $relevant !== null && $relevant >= $this->accumulationLimit();
    }

    /** Whoever is exempt does not declare every quarter, unless paying on the excess (SS051). */
    public function quarterlyDeclarationDue(): bool
    {
        return $this->ssExemptionReason() === null || $this->ssPaysOnExcess();
    }

    /**
     * 25.2 % for a sole trader whose activity is only commercial or industrial, 21.4 % otherwise
     * (SS002, SS003). Never from the activity code alone: the user says it and confirms it.
     */
    public function ssRate(): float
    {
        return $this->answer('work_kind') === 'goods' && $this->yes('commercial_only') === true ? self::SS_RATE_SOLE_TRADER : self::SS_RATE;
    }

    /** SIM001–SIM003. Null until the work is said. */
    public function irsCoefficient(): ?float
    {
        return self::COEFFICIENTS[$this->answer('work_kind')] ?? null;
    }

    /** 1 in the year the activity started, 2 in the next, and so on. */
    public function activityYear(): ?int
    {
        $start = $this->date('activity_started_on');

        return $start === null ? null : max(1, $this->year() - $start->year + 1);
    }

    /**
     * Cut of the coefficient in the early years (art. 31.º, n.os 10 and 11, CIRS): 50 % in the
     * start year, 25 % in the next, unless irsReductionBlocker(). The same as TaxEstimator.
     */
    public function irsReduction(): float
    {
        return $this->irsReductionBlocker() === null ? $this->earlyYearReduction() : 0.0;
    }

    /**
     * Why an early year gets no reduction (INI001, INI002): goods (sales of goods, restaurants and
     * hotels), employment or pension (salary or pension this year), unknown_cessation (a restart
     * without the date the previous activity ended) or recent_restart (less than five years after
     * it). Null outside the early years or when the reduction applies.
     */
    public function irsReductionBlocker(): ?string
    {
        if (! in_array($this->activityYear(), [1, 2], true)) {
            return null;
        }

        if ($this->answer('work_kind') === 'goods') {
            return 'goods';
        }

        if ($this->hasDependentIncome()) {
            return in_array('employment', $this->situation(), true) ? 'employment' : 'pension';
        }

        if ($this->isRestart()) {
            $ended = $this->date('previous_activity_ended_on');

            if ($ended === null) {
                return 'unknown_cessation';
            }

            if ($ended->addYears(5)->gt($this->date('activity_started_on'))) {
                return 'recent_restart';
            }
        }

        return null;
    }

    /** Whether the age allows IRS Jovem this year: 35 or younger on 31 December (JOV004). */
    public function irsJovemAgeAllows(): ?bool
    {
        $birth = $this->date('birth_date');

        return $birth === null ? null : $this->year() - $birth->year <= self::IRS_JOVEM_MAX_AGE;
    }

    /**
     * Share of the income left out by IRS Jovem (JOV005): 100 % in year 1, 75 % in years 2–4,
     * 50 % in 5–7, 25 % in 8–10. Null without it.
     */
    public function irsJovemPercent(): ?float
    {
        $number = (int) $this->answer('irs_jovem_year_number');

        if ($this->yes('irs_jovem') !== true || $this->irsJovemAgeAllows() !== true || $number < 1 || $number > 10) {
            return null;
        }

        return match (true) {
            $number === 1 => 1.0,
            $number <= 4 => 0.75,
            $number <= 7 => 0.5,
            default => 0.25,
        };
    }

    /**
     * VAT exemption of art. 53.º CIVA: what the activity declaration says, or, when the user does
     * not know, whether the turnover expected in Portugal until the end of the year stays at or
     * under the limit (IVA08: exactly the limit still qualifies). Null while nothing says it.
     */
    public function vatExempt(): ?bool
    {
        return match ($this->answer('vat_answer')) {
            'exempt' => true,
            'normal' => false,
            'unknown' => $this->turnover() === null ? null : $this->turnover() <= YearlyParameter::vatExemptionLimit($this->year()),
            default => null,
        };
    }

    /**
     * An exempt account expecting more than the limit: next_year when it stays within the 25 %
     * tolerance, immediately above it (IVA10: exactly the tolerance is still next year).
     */
    public function vatExemptionEnds(): ?string
    {
        $turnover = $this->turnover();
        $tolerance = YearlyParameter::vatExemptionTolerance($this->year());

        if ($this->vatExempt() !== true || $turnover === null || $turnover <= YearlyParameter::vatExemptionLimit($this->year())) {
            return null;
        }

        return $tolerance !== null && $turnover > $tolerance ? 'immediately' : 'next_year';
    }

    /**
     * The fiscal profile to confirm when the user says the deduced Social Security exemption is
     * what Segurança Social Direta shows ("Já confirmei"), as ConfirmFiscalProfile takes it. The
     * VAT stays awaiting confirmation. Null when no exemption was deduced.
     *
     * @return array<string, mixed>|null
     */
    public function exemptionConfirmation(string $legalName, string $nif): ?array
    {
        $reason = $this->ssExemptionReason();

        if ($this->path() !== 'simplified' || $reason === null) {
            return null;
        }

        return [
            'legal_name' => $legalName,
            'country_code' => 'PT',
            'vat_number' => $nif,
            'vat_regime' => $this->vatExempt() ? __('Exempt (Article 53)') : __('Normal regime'),
            'ss_contribution_exempt' => 'yes',
            'activity_started_on' => $this->date('activity_started_on')?->toDateString(),
            'has_dependent_employment' => in_array('employment', $this->situation(), true) ? 'yes' : 'no',
            'recognised_exemptions' => $reason === 'pension'
                ? __('Social Security contributions (Article 157, pension)')
                : __('Social Security contributions (Article 157, accumulation with a job)'),
            'effective_from' => $this->today->toDateString(),
            'confirmed' => true,
        ];
    }

    /**
     * What the side panel shows so far ("O que já sabemos"), newest first: each value with the
     * step whose answers gave it.
     *
     * @return list<array{label: string, value: string, step: string}>
     */
    public function known(): array
    {
        if ($this->path() === 'waiting') {
            return [['label' => __('Activity'), 'value' => __('Not opened yet'), 'step' => 'activity']];
        }

        if ($this->path() === 'organised') {
            return array_values(array_filter([
                ['label' => __('Regime'), 'value' => __('Organised'), 'step' => 'activity'],
                ['label' => __('Taxes'), 'value' => __('No estimates'), 'step' => 'activity'],
                $this->vatExempt() === null ? null : ['label' => __('VAT'), 'value' => $this->vatExempt() ? __('Exempt from VAT') : __('Normal regime'), 'step' => 'organised'],
            ]));
        }

        $items = [];
        $coefficient = $this->irsCoefficient();

        if ($this->answer('accounting_regime') === 'unknown') {
            $items[] = ['label' => __('Regime'), 'value' => __('Simplified (probable)'), 'step' => 'activity'];
        }

        if ($coefficient !== null) {
            $items[] = ['label' => __('IRS coefficient'), 'value' => $this->number($coefficient), 'step' => 'work'];
            $items[] = ['label' => __('Social Security rate'), 'value' => $this->percent($this->ssRate()), 'step' => 'work'];
        }

        if ($this->vatExempt() !== null) {
            $vat = $this->vatExempt() ? __('Exempt from VAT') : __('Normal regime');
            $items[] = ['label' => __('VAT'), 'value' => $this->answer('vat_answer') === 'unknown' ? __(':value (probable)', ['value' => $vat]) : $vat, 'step' => 'work'];
        }

        if ($this->situation() !== []) {
            $items[] = ['label' => __('Contributions'), 'value' => match (true) {
                $this->ssPaysOnExcess() => __('On the excess'),
                $this->ssExemptionReason() !== null => __('Exempt (probable)'),
                default => __('To pay'),
            }, 'step' => 'situation'];
            $items[] = ['label' => __('Quarterly declaration'), 'value' => $this->quarterlyDeclarationDue() ? __('To file') : __('Waived'), 'step' => 'situation'];
        }

        if ($this->irsJovemPercent() !== null) {
            $items[] = ['label' => __('IRS Jovem'), 'value' => __('Year no. :number', ['number' => (int) $this->answer('irs_jovem_year_number')]), 'step' => 'situation'];
        }

        if (($startsOn = $this->ssStartsOn()) !== null) {
            $items[] = ['label' => __('Social Security'), 'value' => __('From :month', ['month' => $this->shortMonth($startsOn)]), 'step' => 'activity'];
        }

        if (in_array($this->activityYear(), [1, 2], true)) {
            $items[] = ['label' => __('IRS'), 'value' => __('Year :number of activity', ['number' => $this->activityYear()]), 'step' => 'activity'];
        }

        return $items;
    }

    /**
     * The three cards of the fiscal profile step: the value in words, why, whether it is deduced
     * or probable, where to confirm it, and the step to change it.
     *
     * @return list<array{key: string, title: string, value: string, reason: string, status: string, confirm: ?string, step: string}>
     */
    public function cards(): array
    {
        return [$this->socialSecurityCard(), $this->irsCard(), $this->vatCard()];
    }

    /**
     * When the user does not know the regime: the simplified one, which applies to whoever did not
     * opt for organised accounting and invoiced up to 200,000 € last year (REG001, REG002).
     */
    public function regimeNote(): ?string
    {
        return $this->answer('accounting_regime') === 'unknown'
            ? __('We assume the simplified regime: it is yours if you never opted for organised accounting and invoiced up to :limit last year. Check your activity declaration, under “Regime de tributação”.', ['limit' => Money::format(self::SIMPLIFIED_REGIME_LIMIT)])
            : null;
    }

    /** One sentence for the year: Social Security and VAT, then the IRS. */
    public function summary(): string
    {
        $year = $this->year();
        $paysSocialSecurity = $this->paysSocialSecurityThisYear();
        $chargesVat = $this->vatExempt() === false;

        $first = match (true) {
            ! $paysSocialSecurity && ! $chargesVat => __('In :year you pay no Social Security and charge no VAT.', ['year' => $year]),
            ! $paysSocialSecurity => __('In :year you pay no Social Security, but you charge VAT.', ['year' => $year]),
            ! $chargesVat => __('In :year you pay Social Security and charge no VAT.', ['year' => $year]),
            default => __('In :year you pay Social Security and charge VAT.', ['year' => $year]),
        };

        $second = $this->irsJovemPercent() !== null
            ? __('For IRS, IRS Jovem leaves out :percent of what you receive.', ['percent' => $this->percent($this->irsJovemPercent())])
            : __('For IRS, :percent of what you receive counts.', ['percent' => $this->percent($this->effectiveCoefficient())]);

        return $first.' '.$second;
    }

    /**
     * The dates that matter from now on, in words.
     *
     * @return list<array{when: string, what: string}>
     */
    public function dates(): array
    {
        $year = $this->year();
        $startsOn = $this->ssStartsOn();
        $exempt = $this->ssExemptionReason() !== null && ! $this->ssPaysOnExcess();

        return array_values(array_filter([
            ['when' => __('In :year', ['year' => $year]), 'what' => __('We tell you how much to set aside for taxes, month by month.')],
            $startsOn !== null && $startsOn->isAfter($this->today) ? [
                'when' => $this->longDate($startsOn),
                'what' => $exempt ? __('Social Security starts counting, if the exemption has ended by then.') : __('Social Security starts counting. Until your first quarterly declaration you pay the minimum of :amount.', ['amount' => Money::format(TaxEstimator::SS_MINIMUM_CONTRIBUTION)]),
            ] : null,
            ['when' => __('April to June :year', ['year' => $year + 1]), 'what' => __('You file your :year IRS return.', ['year' => $year])],
            $this->vatExempt() === true
                ? ['when' => __('At :amount', ['amount' => Money::format(YearlyParameter::vatExemptionLimit($year))]), 'what' => __('We warn you: VAT changes in January of the following year.')]
                : ['when' => __('Every quarter'), 'what' => __('You file the periodic VAT return.')],
            $this->ssExemptionReason() === 'accumulation'
                ? ['when' => __('If the job ends'), 'what' => __('Tell us. You pay Social Security again from the following month.')]
                : null,
        ]));
    }

    /** @return array{key: string, title: string, value: string, reason: string, status: string, confirm: ?string, step: string} */
    private function socialSecurityCard(): array
    {
        $reason = $this->ssExemptionReason();
        $ias = Money::format(YearlyParameter::ias($this->year()));
        $limit = Money::format($this->accumulationLimit());
        $relevant = $this->relevantMonthlyIncome();
        $confirmExemption = __('In Social Security Direct, under “Situação atual”, it should say “Não tem obrigação de contribuir”. You can confirm now or later, in Tax settings.');

        if ($reason === 'accumulation') {
            return $this->card('social_security', __('Social Security'), ...($this->ssPaysOnExcess() ? [
                __('You pay only on the excess'),
                __('You have a job with another employer, but your expected relevant income (:amount a month) reaches :limit (4 IAS). You pay :rate on what goes over it and file the quarterly declaration.', [
                    'amount' => Money::format($relevant), 'limit' => $limit, 'rate' => $this->percent($this->ssRate()),
                ]),
            ] : [
                __('You don’t pay, for now'),
                $relevant === null
                    ? __('You have a job with another employer, with a salary of at least :ias, so you are exempt while your relevant income stays under :limit a month. You also don’t file the quarterly declaration.', ['ias' => $ias, 'limit' => $limit])
                    : __('You have a job with another employer, with a salary of at least :ias, and your expected relevant income stays under :limit a month. You also don’t file the quarterly declaration.', ['ias' => $ias, 'limit' => $limit]),
            ]), status: self::PROBABLE, confirm: $confirmExemption, step: 'situation');
        }

        if ($reason === 'pension') {
            return $this->card('social_security', __('Social Security'), __('You don’t pay, for now'),
                __('You receive an old-age or disability pension. If it can be combined with your activity, you are exempt from contributions and from the quarterly declaration.'),
                status: self::PROBABLE, confirm: $confirmExemption, step: 'situation');
        }

        $startsOn = $this->ssStartsOn();
        $sentences = [__('The rate is :rate on :share of what you receive.', [
            'rate' => $this->percent($this->ssRate()),
            'share' => $this->answer('work_kind') === 'goods' ? '20%' : '70%',
        ])];

        if ($startsOn !== null) {
            $sentences[] = $this->isRestart()
                ? __('After a restart, the date in Social Security Direct counts: :date.', ['date' => $this->longDate($startsOn)])
                : __('On a first activity, Social Security only counts from the first day of the 12th month after you started: :date.', ['date' => $this->longDate($startsOn)]);
        }

        $sentences[] = match ($this->employmentBlocker()) {
            'same_employer' => __('Your employer is linked to your clients, so there is no exemption: what you invoice them counts towards the contributions of your employment contract.'),
            'low_salary' => __('Your salary is under :ias a month (1 IAS), so there is no exemption.', ['ias' => $ias]),
            'cga' => __('With the CGA the exemption is not automatic: request it from Social Security, with proof. Until then, we count the contributions.'),
            default => null,
        };

        $soleTrader = $this->ssRate() === self::SS_RATE_SOLE_TRADER;

        if ($soleTrader) {
            $sentences[] = __('As your activity is only commercial or industrial, the rate is :rate.', ['rate' => $this->percent(self::SS_RATE_SOLE_TRADER)]);
        }

        $probable = $soleTrader || $this->employmentBlocker() === 'cga';

        return $this->card('social_security', __('Social Security'),
            $startsOn !== null && $startsOn->isAfter($this->today)
                ? __('You pay from :month', ['month' => $this->monthYear($startsOn)])
                : __('You pay :rate on :share of your income', ['rate' => $this->percent($this->ssRate()), 'share' => $this->answer('work_kind') === 'goods' ? '20%' : '70%']),
            implode(' ', array_filter($sentences)),
            status: $probable ? self::PROBABLE : self::DEDUCED,
            confirm: $probable ? __('In Social Security Direct, under “Forma de atividade (taxa)” and “Situação atual”.') : null,
            step: $soleTrader ? 'work' : 'situation');
    }

    /** @return array{key: string, title: string, value: string, reason: string, status: string, confirm: ?string, step: string} */
    private function irsCard(): array
    {
        $coefficient = $this->irsCoefficient() ?? self::COEFFICIENTS['listed'];
        $profession = trim((string) $this->answer('profession'));

        $sentences = [match ($this->answer('work_kind')) {
            'other' => __('Your services are not on the list of Article 151, so the coefficient is :coefficient.', ['coefficient' => $this->number($coefficient)]),
            'goods' => __('You sell products, food or lodging, so the coefficient is :coefficient.', ['coefficient' => $this->number($coefficient)]),
            default => $profession !== ''
                ? __('Your occupation (:occupation) is on the list of Article 151, so the coefficient is :coefficient.', ['occupation' => $profession, 'coefficient' => $this->number($coefficient)])
                : __('Your occupation is on the list of Article 151, so the coefficient is :coefficient.', ['coefficient' => $this->number($coefficient)]),
        }];

        if ($this->irsJovemPercent() !== null) {
            $sentences[] = __('Before that, IRS Jovem exempts :percent of the income.', ['percent' => $this->percent($this->irsJovemPercent())]);
        }

        $year = $this->year();
        $sentences[] = match ($this->irsReductionBlocker()) {
            'goods' => __('Sales of goods get no reduction in the first years.'),
            'employment' => __('No first-years reduction, because you have a salary in :year.', ['year' => $year]),
            'pension' => __('No first-years reduction, because you receive a pension in :year.', ['year' => $year]),
            'unknown_cessation' => __('No first-years reduction: without the date your previous activity ended, we cannot apply it.'),
            'recent_restart' => __('No first-years reduction, because you restarted less than five years after ending your previous activity.'),
            default => $this->irsReduction() > 0
                ? __('In year :number of activity the coefficient is cut by :percent.', ['number' => $this->activityYear(), 'percent' => $this->percent($this->irsReduction())])
                : null,
        };

        return $this->card('irs', __('IRS'), __(':percent of what you receive counts', ['percent' => $this->percent($this->effectiveCoefficient())]),
            implode(' ', array_filter($sentences)), status: self::DEDUCED, confirm: null, step: 'work');
    }

    /** @return array{key: string, title: string, value: string, reason: string, status: string, confirm: ?string, step: string} */
    private function vatCard(): array
    {
        $year = $this->year();
        $exempt = $this->vatExempt();
        $turnover = $this->turnover();
        $limit = YearlyParameter::vatExemptionLimit($year);
        $replacements = [
            'amount' => Money::format($turnover),
            'year' => $year,
            'limit' => Money::format($limit),
            'tolerance' => Money::format(YearlyParameter::vatExemptionTolerance($year) ?? $limit),
            'next' => $year + 1,
        ];

        $reason = match (true) {
            $exempt === false && $this->answer('vat_answer') === 'normal' => __('Your activity declaration says normal regime: you add VAT to your invoices and file the periodic VAT return.'),
            $exempt === false => __('You expect to invoice :amount by the end of :year, over the Article 53 limit of :limit, so you should be in the normal regime.', $replacements),
            $turnover === null => __('Your activity declaration says you are exempt (Article 53).'),
            $this->vatExemptionEnds() === 'immediately' => __('You expect to invoice :amount, over :tolerance: the exemption ends as soon as you go over that amount.', $replacements),
            $this->vatExemptionEnds() === 'next_year' => __('You expect to invoice :amount, over :limit: VAT changes to the normal regime in January :next.', $replacements),
            $turnover === $limit => __('You expect to invoice :amount by the end of :year, the Article 53 limit itself, which still qualifies.', $replacements),
            default => __('You expect to invoice :amount by the end of :year, under the Article 53 limit of :limit.', $replacements),
        };

        $probable = $this->answer('vat_answer') === 'unknown';

        return $this->card('vat', __('VAT'), $exempt ? __('You don’t charge VAT') : __('You charge VAT'), $reason,
            status: $probable ? self::PROBABLE : self::DEDUCED,
            confirm: $probable ? __('In your activity declaration, under “Enquadramento em IVA”. The exemption only applies without exports.') : null,
            step: 'work');
    }

    /** @return array{key: string, title: string, value: string, reason: string, status: string, confirm: ?string, step: string} */
    private function card(string $key, string $title, string $value, string $reason, string $status, ?string $confirm, string $step): array
    {
        return compact('key', 'title', 'value', 'reason', 'status', 'confirm', 'step');
    }

    /** No contribution in the whole year: exempt (and not on the excess), or not counting until next year. */
    private function paysSocialSecurityThisYear(): bool
    {
        if ($this->ssExemptionReason() !== null && ! $this->ssPaysOnExcess()) {
            return false;
        }

        $startsOn = $this->ssStartsOn();

        return $startsOn === null || $startsOn->year <= $this->year();
    }

    private function effectiveCoefficient(): float
    {
        return ($this->irsCoefficient() ?? self::COEFFICIENTS['listed']) * (1 - $this->irsReduction());
    }

    /** 50 % in the start year, 25 % in the next, before any blocker; never for sales of goods (INI001). */
    private function earlyYearReduction(): float
    {
        if ($this->answer('work_kind') === 'goods') {
            return 0.0;
        }

        return match ($this->activityYear()) {
            1 => 0.5,
            2 => 0.25,
            default => 0.0,
        };
    }

    private function isRestart(): bool
    {
        return $this->answer('activity_start_kind') === 'restart';
    }

    /** Cents; null when not answered. */
    private function turnover(): ?int
    {
        $turnover = $this->answer('expected_turnover');

        return $turnover === null ? null : max(0, (int) $turnover);
    }

    private function answer(string $key): mixed
    {
        $value = $this->answers[$key] ?? null;

        return blank($value) ? null : $value;
    }

    /** yes | no (or a boolean) as a boolean; null when not answered. */
    private function yes(string $key): ?bool
    {
        return match ($this->answer($key)) {
            'yes', true, 1, '1' => true,
            'no', false, 0, '0' => false,
            default => null,
        };
    }

    private function date(string $key): ?CarbonImmutable
    {
        $value = $this->answer($key);

        try {
            return $value === null ? null : CarbonImmutable::parse($value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    private function number(float $value): string
    {
        return (string) Number::format($value, maxPrecision: 4, locale: app()->getLocale());
    }

    /** 0.214 → "21,4%". */
    private function percent(float $fraction): string
    {
        return Number::format(round($fraction * 100, 2), maxPrecision: 2, locale: app()->getLocale()).'%';
    }

    private function shortMonth(CarbonImmutable $date): string
    {
        return $date->locale(app()->getLocale())->translatedFormat(__('M Y'));
    }

    private function monthYear(CarbonImmutable $date): string
    {
        return $date->locale(app()->getLocale())->translatedFormat(__('F Y'));
    }

    private function longDate(CarbonImmutable $date): string
    {
        return $date->locale(app()->getLocale())->translatedFormat(__('F j, Y'));
    }
}
