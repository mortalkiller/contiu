<?php

namespace App\Support;

use App\Enums\Language;
use App\Filament\Pages\FinanceSettingsPage;
use App\Filament\Pages\OpenActivity;
use App\Filament\Pages\SocialSecurity as SocialSecurityPage;
use App\Filament\Pages\TaxEstimate;
use App\Filament\Pages\TerritorialTurnoverPage;
use App\Filament\Pages\VatReviewPage;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Models\Account;
use App\Models\Expense;
use App\Models\FiscalProfile;
use App\Models\TaxObligationCompletion;
use App\Models\User;
use App\Models\VatPeriodConfirmation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What the account has to pay or deliver, month by month (the "Este mês" card and its alerts).
 * ESTIMATES ONLY — the amounts come from TaxEstimator and ReverseChargeVat.
 *
 * Keys and deadlines (rule IDs of docs/fiscal/memoria-fiscal-ti-eni-portugal-2026.md):
 * - ss:YYYY-MM — Segurança Social paid in the month, between the 10th and the 20th: the contribution
 *   of the month before (art. 155.º, SS060);
 * - ss-declaration:YYYY-Qn — quarterly SS declaration, by the end of the month after the quarter (SS050);
 * - ss-annual:YYYY — in January, the confirmation of the year before's declared income (SS054);
 * - vat-return:YYYY-MM or YYYY-Qn — periodic VAT return outside the art. 53.º exemption, by the 20th
 *   of the second month after the period, paid by the 25th; June and the second quarter in
 *   September (IVA26, IVA27);
 * - vat-reverse:YYYY-MM — with the VAT exemption, VAT self-assessed on the month's foreign services,
 *   by the end of the next month (IVA18); outside it, that VAT goes in the periodic return;
 * - recapitulativa:operacoes:YYYY-MM or YYYY-Qn — verified EU services by operation date;
 * - irs-modelo3:YYYY — the year's IRS return, from 1 April to 30 June of the next year;
 * - irs-ppc:YYYY-N — the IRS payments on account of July, September and December (PPC001).
 *
 * Every deadline goes through PortugueseDeadline: weekends and national holidays move it to the next
 * working day, and one in August ends on 31 August (OBR03, SS052, SS061).
 *
 * An account waiting to open its activity has no obligations; one with organised accounting gets
 * no Social Security figures nor declarations (the Social Security sets its base from the taxable
 * profit: SS034, SS051).
 *
 * An obligation not marked done stays listed after its deadline as overdue, for at most
 * OVERDUE_MONTHS back and never from before the account existed (it could not have been marked),
 * unless the account set the date to track them from (Definições fiscais).
 */
final class TaxObligations
{
    public const OVERDUE_MONTHS = 12;

    private readonly CarbonImmutable $today;

    private readonly CarbonImmutable $month;

    private ?TaxEstimator $estimator = null;

    private bool $vatThresholdLoaded = false;

    /** @var array{label: string, amount: int, percent: float, status: string, detail: string, notice: ?string, advisory: ?string, usesReceipts: bool}|null */
    private ?array $vatThreshold = null;

    /** @var Collection<string, TaxObligationCompletion>|null */
    private ?Collection $completions = null;

    /** @var Collection<string, VatPeriodConfirmation>|null */
    private ?Collection $vatConfirmations = null;

    public function __construct(private readonly FinanceSettings $settings, CarbonImmutable $today)
    {
        $this->today = $today->startOfDay();
        $this->month = $today->startOfMonth();
    }

    /**
     * Due this month (the month's SS even when exempt) and still undone from earlier months, by
     * deadline; then the notes (incomplete settings, VAT threshold, foreign expenses with VAT).
     *
     * @return list<TaxObligation>
     */
    public function thisMonth(): array
    {
        return $this->openAndNotes($this->dueIn($this->month), $this->dueEarlier());
    }

    /**
     * Marked done and still relevant: due this month, or overdue ones marked this month.
     *
     * @return list<TaxObligation>
     */
    public function done(): array
    {
        return $this->completed($this->dueIn($this->month), $this->dueEarlier());
    }

    /** @return array{rows: list<TaxObligation>, done: list<TaxObligation>} */
    public function monthOverview(): array
    {
        $current = $this->dueIn($this->month);
        $earlier = $this->dueEarlier();

        return [
            'rows' => $this->openAndNotes($current, $earlier),
            'done' => $this->completed($current, $earlier),
        ];
    }

    /**
     * @param  list<TaxObligation>  $current
     * @param  list<TaxObligation>  $earlier
     * @return list<TaxObligation>
     */
    private function openAndNotes(array $current, array $earlier): array
    {
        $current = array_filter($current, fn (TaxObligation $obligation): bool => $obligation->status !== 'done');
        $overdue = array_filter($earlier, fn (TaxObligation $obligation): bool => $obligation->status === 'overdue');

        return [...$this->byDeadline([...$overdue, ...$current]), ...$this->notes()];
    }

    /**
     * @param  list<TaxObligation>  $current
     * @param  list<TaxObligation>  $earlier
     * @return list<TaxObligation>
     */
    private function completed(array $current, array $earlier): array
    {
        $earlier = array_filter($earlier, fn (TaxObligation $obligation): bool => $obligation->status === 'done'
            && $this->completions()[$obligation->key]->completed_at->gte($this->month));
        $current = array_filter($current, fn (TaxObligation $obligation): bool => $obligation->status === 'done');

        return $this->byDeadline([...$earlier, ...$current]);
    }

    /**
     * Coming in the next months, without amounts: the next SS declaration and, in April and May,
     * the IRS return.
     *
     * @return list<TaxObligation>
     */
    public function upcoming(): array
    {
        if ($this->settings->isIncomplete() || ! $this->settings->activityOpened()) {
            return [];
        }

        $declarationMonth = collect(range(1, 3))
            ->map(fn (int $offset) => $this->month->addMonths($offset))
            ->first(fn (CarbonImmutable $month) => $month->month % 3 === 1);

        $upcoming = [
            $this->ssDeclaration($declarationMonth->subMonths(3)),
            in_array($this->month->month, [4, 5], true) ? $this->irsReturn($this->month->year - 1) : null,
        ];

        return array_values(array_filter($upcoming, fn (?TaxObligation $obligation) => $obligation !== null && $obligation->status !== 'done'));
    }

    /**
     * Every obligation with a deadline between the two dates (inclusive), by deadline, for the
     * calendar: past and future months alike, from the first tracked month on. Exempt ones have no
     * deadline and are left out.
     *
     * @return list<TaxObligation>
     */
    public function between(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $from = $from->startOfDay();
        $to = $to->startOfDay();
        $obligations = [];

        for ($month = $from->startOfMonth()->max($this->trackedFrom()); $month->lte($to); $month = $month->addMonth()) {
            array_push($obligations, ...$this->dueIn($month));
        }

        return $this->byDeadline(array_filter($obligations, fn (TaxObligation $obligation) => $obligation->dueOn !== null
            && $obligation->dueOn->betweenIncluded($from, $to)));
    }

    /** @return list<TaxObligation> */
    public function pending(): array
    {
        return array_values(array_filter($this->thisMonth(), fn (TaxObligation $obligation) => in_array($obligation->status, ['due', 'overdue'], true)));
    }

    public function complete(string $key, ?User $user): void
    {
        TaxObligationCompletion::query()->firstOrCreate(['key' => $key], ['completed_at' => now(), 'user_id' => $user?->getKey()]);
        $this->completions = null;
    }

    public function reopen(string $key): void
    {
        TaxObligationCompletion::query()->where('key', $key)->first()?->delete();
        $this->completions = null;
    }

    /** @return array{amount: int, confirmed: bool} */
    public function forecastSocialSecurity(CarbonImmutable $month): array
    {
        if ($this->completions()->has('ss:'.$month->format('Y-m'))) {
            return ['amount' => 0, 'confirmed' => false];
        }

        $confirmed = app(SocialSecurity::class)->confirmedPaymentInMonth($month);

        return [
            'amount' => $confirmed ?? $this->estimator()->ssPaymentInMonth($month),
            'confirmed' => $confirmed !== null,
        ];
    }

    /** @return list<array{dueOn: CarbonImmutable, amount: int}> */
    public function forecastVat(CarbonImmutable $until): array
    {
        $confirmations = VatPeriodConfirmation::query()->get()
            ->map(fn (VatPeriodConfirmation $row): VatPeriodReview => VatPeriodReview::forPeriod($row->starts_on->year, $row->starts_on->month, $row->months))
            ->filter(fn (VatPeriodReview $review): bool => $review->confirmedBalance() !== null);

        return $confirmations
            ->filter(fn (VatPeriodReview $review): bool => ($review->amountDue() ?? 0) > 0
                && $review->dueOn()?->lte($until)
                && ! $review->isPaid()
                && ! $confirmations->contains(fn (VatPeriodReview $other): bool => $other !== $review
                    && $other->start->lt($review->start->addMonths($review->months))
                    && $review->start->lt($other->start->addMonths($other->months))))
            ->map(fn (VatPeriodReview $review): array => ['dueOn' => $review->dueOn(), 'amount' => $review->amountDue()])
            ->values()->all();
    }

    /**
     * Every obligation whose deadline falls in the month (the month's SS also when exempt).
     *
     * @return list<TaxObligation>
     */
    private function dueIn(CarbonImmutable $month): array
    {
        if (! $this->settings->activityOpened()) {
            return [];
        }

        $previous = $month->subMonth();

        return [...array_values(array_filter([
            $this->settings->isIncomplete() ? null : $this->ss($month),
            $month->month % 3 === 1 ? $this->ssDeclaration($month->subMonths(3)) : null,
            $month->month === 1 ? $this->ssAnnualConfirmation($month->year - 1) : null,
            $this->vatReverse($previous),
            ...$this->vatReturnsDueIn($month),
            $month->month === 6 ? $this->irsReturn($month->year - 1) : null,
            ...$this->irsPrepaymentsDueIn($month),
        ])), ...$this->recapitulativasDueIn($month)];
    }

    /**
     * Obligations due in the months before this one that can still show as overdue.
     *
     * @return list<TaxObligation>
     */
    private function dueEarlier(): array
    {
        $obligations = [];

        for ($month = $this->trackedFrom(); $month->lt($this->month); $month = $month->addMonth()) {
            array_push($obligations, ...$this->dueIn($month));
        }

        return $obligations;
    }

    /**
     * The first month whose obligations are listed: at most OVERDUE_MONTHS back, and never before
     * the date the account chose to track from (or, without one, the month it was created).
     */
    private function trackedFrom(): CarbonImmutable
    {
        $from = $this->month->subMonths(self::OVERDUE_MONTHS);
        $created = CurrentAccount::get()?->created_at;
        $since = $this->settings->obligationsTrackedFrom() ?? ($created === null ? null : CarbonImmutable::instance($created));

        return $since === null ? $from : $from->max($since->startOfMonth());
    }

    private function ss(CarbonImmutable $month): TaxObligation
    {
        $key = 'ss:'.$month->format('Y-m');
        $contributionMonth = $month->subMonth();
        $source = $contributionMonth->firstOfQuarter()->subMonths(3);
        $sourceClosed = $source->endOfQuarter()->lt($this->today);
        $url = $sourceClosed
            ? $this->ssReviewUrl($source)
            : $this->url(fn (Account $account) => TaxEstimate::getUrl(['year' => $source->year], panel: 'admin', tenant: $account));
        $confirmed = app(SocialSecurity::class)->confirmedPaymentInMonth($month);
        $organised = $this->settings->accountingRegime() === 'organised';
        $status = $organised ? 'charged' : $this->estimator()->ssPaymentStatus($month);
        $estimate = $organised || ! $sourceClosed ? null : $this->estimator()->ssPaymentInMonth($month);

        if ($confirmed === 0 || ($confirmed === null && ($status !== 'charged' || $estimate === 0))) {
            $detail = match (true) {
                $confirmed === 0 => __('No contribution due · confirmed amount'),
                $status === 'exempt' => __('Exempt (Art. 157)'),
                $status === 'charged' => __('No contribution due · under €5 on the excess over 4 IAS'),
                default => __('Exempt (first enrollment)'),
            };

            return new TaxObligation($key, 'ss', __('Social Security'), null, null, null, $detail, $url, 'exempt');
        }

        // While the quarter of income is still open its figure would be partial (or the minimum).
        // With organised accounting the Social Security sets the amount from the taxable profit.
        $amount = $confirmed ?? $estimate;
        $detail = __('Pay between :month 10 and 20 · :contributionMonth contribution · income from :quarter', [
            'month' => self::monthName($month),
            'contributionMonth' => self::monthName($contributionMonth),
            'quarter' => self::quarterName($source),
        ]);
        $amountStatus = match (true) {
            $confirmed !== null => __('confirmed amount'),
            $amount === null && $organised => __('amount set by the Social Security from your taxable profit'),
            $amount === null => __('amount to be determined'),
            $amount === TaxEstimator::SS_MINIMUM_CONTRIBUTION => __('minimum contribution'),
            default => null,
        };
        if ($amountStatus !== null) {
            $detail .= ' · '.$amountStatus;
        }
        $dueOn = PortugueseDeadline::of($month->setDay(20));

        return new TaxObligation($key, 'ss', __('Social Security'), $amount, $dueOn, $month->setDay(10), $detail, $url, $this->status($key, $dueOn));
    }

    /**
     * Declared in the month after the quarter (SS050). Only workers who must contribute declare
     * (art. 151.º-A, n.º 1, SS051): not for a quarter wholly before the enrolment takes effect, nor
     * with a contribution exemption; with the accumulation one, the quarter whose relevant income
     * reached 4 IAS a month is declared again (SS045). Not with organised accounting either.
     */
    private function ssDeclaration(CarbonImmutable $quarter): ?TaxObligation
    {
        $dueOn = PortugueseDeadline::of($quarter->addMonths(3)->endOfMonth()->startOfDay());

        if ($this->settings->isIncomplete() || $this->settings->accountingRegime() === 'organised'
            || $this->estimator()->ssMonthStatus($quarter->addMonths(3)) !== 'charged'
            || $this->estimator()->ssMonthStatus($quarter->addMonths(2)) === 'first_enrolment') {
            return null;
        }

        $key = 'ss-declaration:'.$quarter->year.'-Q'.$quarter->quarter;

        return new TaxObligation(
            $key,
            'ss-declaration',
            __('Quarterly Social Security return'),
            null,
            $dueOn,
            null,
            __('Due by :month :day · income from :quarter :year', [
                'month' => self::monthName($dueOn),
                'day' => $dueOn->day,
                'quarter' => self::quarterName($quarter),
                'year' => $quarter->year,
            ]),
            $quarter->endOfQuarter()->lt($this->today) ? $this->ssReviewUrl($quarter) : null,
            $this->status($key, $dueOn),
        );
    }

    private function ssReviewUrl(CarbonImmutable $quarter): ?string
    {
        return $this->url(fn (Account $account): string => SocialSecurityPage::getUrl([
            'quarter' => $quarter->year.'-Q'.$quarter->quarter,
        ], panel: 'admin', tenant: $account));
    }

    /**
     * Only for a month with foreign services to self-assess, with the VAT exemption (art. 27.º,
     * n.º 3, IVA18). Outside it, the periodic return carries that VAT.
     */
    private function vatReverse(CarbonImmutable $month): ?TaxObligation
    {
        if (! $this->estimator()->vatExempt($month->endOfMonth()->startOfDay())) {
            return null;
        }

        $vat = (new ReverseChargeVat($this->settings))->forMonth($month);

        if ($vat['vat'] <= 0) {
            return null;
        }

        $key = 'vat-reverse:'.$month->format('Y-m');
        $dueOn = PortugueseDeadline::of($month->addMonth()->endOfMonth()->startOfDay());

        return new TaxObligation(
            $key,
            'vat-reverse',
            __('VAT on purchases from abroad'),
            $vat['vat'],
            $dueOn,
            null,
            __('Declare and pay by :dueMonth :day · :month purchases (:base excluding VAT)', [
                'dueMonth' => self::monthName($dueOn),
                'day' => $dueOn->day,
                'month' => self::monthName($month),
                'base' => Money::format($vat['base']),
            ]),
            $this->url(fn (Account $account) => ExpenseResource::getUrl('index', [
                'tableFilters' => ['year' => ['value' => $month->year], 'month' => ['value' => $month->month]],
            ], panel: 'admin', tenant: $account)),
            $this->status($key, $dueOn),
        );
    }

    /** @return list<TaxObligation> */
    private function recapitulativasDueIn(CarbonImmutable $month): array
    {
        $recap = new EuServicesRecap;
        $previous = $month->subMonth();
        $rows = [];

        foreach ($recap->basesDueIn($month) as $frequency => $base) {
            $period = $frequency === 1 ? $previous->format('Y-m') : $previous->year.'-Q'.$previous->quarter;
            $label = $frequency === 1 ? self::monthName($previous) : self::quarterName($previous->startOfQuarter());
            $key = 'recapitulativa:operacoes:'.$period;
            $dueOn = $recap->deadline($month);

            $rows[] = new TaxObligation(
                $key,
                'recapitulativa',
                __('Recapitulative statement'),
                null,
                $dueOn,
                null,
                __('Services to EU businesses · :period :year · documented taxable amount :base', [
                    'period' => $label,
                    'year' => $previous->year,
                    'base' => Money::format($base),
                ]),
                $this->url(fn (Account $account): string => FiscalDocumentResource::getUrl('index', panel: 'admin', tenant: $account)),
                $this->status($key, $dueOn),
            );
        }

        return $rows;
    }

    /** The return of an income year, once the activity had started by then. */
    private function irsReturn(int $year): ?TaxObligation
    {
        $started = $this->settings->activityStartedOn();

        if ($this->settings->isIncomplete() || $started === null || $started->year > $year) {
            return null;
        }

        $key = 'irs-modelo3:'.$year;
        $dueOn = PortugueseDeadline::of(CarbonImmutable::create($year + 1, 6, 30));

        return new TaxObligation(
            $key,
            'irs-modelo3',
            __('Personal income tax return (Form 3)'),
            null,
            $dueOn,
            CarbonImmutable::create($year + 1, 4, 1),
            __('File April 1 through June 30 with the Social Security annex · :year income', ['year' => $year]),
            $this->url(fn (Account $account) => TaxEstimate::getUrl(['year' => $year], panel: 'admin', tenant: $account)),
            $this->status($key, $dueOn),
        );
    }

    /**
     * In January, the confirmation of the income declared for the year before, for whoever had to
     * file at least one quarterly declaration about that year (SS054). It goes with January's
     * declaration, by the same deadline.
     */
    private function ssAnnualConfirmation(int $year): ?TaxObligation
    {
        $declared = collect(range(1, 4))
            ->map(fn (int $quarter) => $this->ssDeclaration(CarbonImmutable::create($year, $quarter * 3 - 2, 1)))
            ->filter();

        if ($declared->isEmpty()) {
            return null;
        }

        $key = 'ss-annual:'.$year;
        $dueOn = PortugueseDeadline::of(CarbonImmutable::create($year + 1, 1, 31));

        return new TaxObligation(
            $key,
            'ss-annual',
            __('Social Security annual confirmation'),
            null,
            $dueOn,
            null,
            __('Confirm or correct the income declared for :year, with January’s quarterly declaration', ['year' => $year]),
            $this->ssReviewUrl(CarbonImmutable::create($year, 10, 1)),
            $this->status($key, $dueOn),
        );
    }

    /**
     * The periodic VAT returns due in the month, outside the art. 53.º exemption: the period that
     * ended two months before, by the 20th; June and the second quarter are due in September
     * (IVA26, IVA27). Monthly or quarterly as the account says (or its confirmed tax profile).
     *
     * @return list<TaxObligation>
     */
    private function vatReturnsDueIn(CarbonImmutable $month): array
    {
        // Until the settings are complete the VAT regime is only the default, not the account's.
        if ($month->month === 8 || $this->settings->isIncomplete()) {
            return [];
        }

        $rows = [];
        $periodEnds = $month->month === 9 ? [$month->subMonths(3), $month->subMonths(2)] : [$month->subMonths(2)];

        foreach ($periodEnds as $periodEnd) {
            $months = $this->vatPeriodMonths($periodEnd);

            if (($months === 3 && $periodEnd->month % 3 !== 0) || $this->estimator()->vatExempt($periodEnd->endOfMonth()->startOfDay())) {
                continue;
            }

            $start = $periodEnd->subMonths($months - 1)->startOfMonth();
            $key = 'vat-return:'.($months === 1 ? $start->format('Y-m') : $start->year.'-Q'.$start->quarter);
            $dueOn = PortugueseDeadline::of($month->setDay(20));
            $payBy = PortugueseDeadline::of($month->setDay(25));
            $confirmation = $this->vatConfirmations()->get($start->toDateString().'|'.$months);
            $amount = $confirmation === null ? null : VatPeriodReview::forPeriod($start->year, $start->month, $months)->amountDue();

            $rows[] = new TaxObligation(
                $key,
                'vat-return',
                __('Periodic VAT return'),
                $amount,
                $dueOn,
                null,
                __('File by :month :day and pay by :payDay · :period :year', [
                    'month' => self::monthName($dueOn),
                    'day' => $dueOn->day,
                    'payDay' => $payBy->day,
                    'period' => $months === 1 ? self::monthName($start) : self::quarterName($start),
                    'year' => $start->year,
                ]),
                $this->url(fn (Account $account): string => VatReviewPage::getUrl([
                    'year' => $start->year, 'month' => $start->month, 'months' => $months,
                ], panel: 'admin', tenant: $account)),
                $this->status($key, $dueOn),
            );
        }

        return $rows;
    }

    /** 1 or 3: the confirmed tax profile's VAT regime in force at the period's end, else the settings. */
    private function vatPeriodMonths(CarbonImmutable $periodEnd): int
    {
        $profile = FiscalProfile::effectiveOn($periodEnd->endOfMonth()->startOfDay());

        return ($profile === null ? null : EuServiceEvidence::recapMonths($profile)) ?? $this->settings->vatPeriodMonths();
    }

    /** @return Collection<string, VatPeriodConfirmation> by "start date|months" */
    private function vatConfirmations(): Collection
    {
        return $this->vatConfirmations ??= VatPeriodConfirmation::query()->get()
            ->keyBy(fn (VatPeriodConfirmation $row): string => $row->starts_on->toDateString().'|'.$row->months);
    }

    /**
     * The IRS payments on account due in the month (July, September, December), from the assessment
     * the account entered (IrsPrepayments). Only those of 50 € or more are due (PPC002).
     *
     * @return list<TaxObligation>
     */
    private function irsPrepaymentsDueIn(CarbonImmutable $month): array
    {
        $rows = [];

        foreach ((new IrsPrepayments($this->settings))->forYear($month->year) as $number => $installment) {
            if ($installment['dueOn']->month !== $month->month) {
                continue;
            }

            $key = 'irs-ppc:'.$month->year.'-'.$number;

            $rows[] = new TaxObligation(
                $key,
                'irs-ppc',
                __('Personal income tax payment on account'),
                $installment['amount'],
                $installment['dueOn'],
                null,
                __('Payment :number of 3 · computed from the :year assessment', ['number' => $number, 'year' => $month->year - 2]),
                $this->url(fn (Account $account): string => FinanceSettingsPage::getUrl(panel: 'admin', tenant: $account)),
                $this->status($key, $installment['dueOn']),
            );
        }

        return $rows;
    }

    /** @return array{label: string, amount: int, percent: float, status: string, detail: string, notice: ?string, advisory: ?string, usesReceipts: bool}|null */
    public function vatThreshold(): ?array
    {
        if ($this->vatThresholdLoaded) {
            return $this->vatThreshold;
        }

        $this->vatThresholdLoaded = true;
        $estimator = $this->estimator();

        return $this->vatThreshold = $estimator->vatExempt($this->today)
            ? TerritorialTurnover::forYear($this->today->year, $this->today)->threshold($estimator)
            : null;
    }

    private function territorialReviewUrl(int $year): ?string
    {
        return $this->url(fn (Account $account): string => TerritorialTurnoverPage::getUrl(['year' => $year], panel: 'admin', tenant: $account));
    }

    /**
     * Rows without a deadline: what is missing in the settings, the art. 53.º threshold and the
     * foreign services of last month that carry VAT (not self-assessed: worth a look).
     *
     * @return list<TaxObligation>
     */
    private function notes(): array
    {
        $notes = [];
        $settingsUrl = $this->url(fn (Account $account) => FinanceSettingsPage::getUrl(panel: 'admin', tenant: $account));

        if (! $this->settings->activityOpened()) {
            return [new TaxObligation(
                'activity-not-opened',
                'activity-not-opened',
                __('You have not opened your activity yet'),
                null,
                null,
                null,
                __('Taxes and the tax calendar start on the day you open it with the Tax Authority. When you do, press “I have opened my activity”.'),
                $this->url(fn (Account $account) => OpenActivity::getUrl(panel: 'admin', tenant: $account)),
                'info',
            )];
        }

        // After a restart only the Social Security knows when the obligation starts again (SS012).
        if ($this->settings->activityStartKind() === 'restart' && $this->settings->ssEnrolledFrom() === null) {
            $notes[] = new TaxObligation(
                'ss-enrolment-date',
                'ss-enrolment-date',
                __('Enter your Social Security start date'),
                null,
                null,
                null,
                __('After a restart, contributions start on the date shown in Social Security Direct (“Enquadrado a partir de”). Until you enter it, they are counted from the month of the restart.'),
                $settingsUrl,
                'info',
            );
        }

        if (($waiver = new WithholdingWaiver($this->settings))->hasEnded($this->today)) {
            $endsOn = $waiver->endsOn($this->today->year);
            $notes[] = new TaxObligation(
                'withholding-waiver-ended:'.$this->today->year,
                'withholding-waiver-ended',
                __('Your withholding waiver has ended'),
                null,
                null,
                null,
                $endsOn === null
                    ? __('Your income last year reached the limit, so business clients withhold IRS on your receipts this year (Article 101-B).')
                    : __('Your income reached the limit, so from :date business clients withhold IRS on your receipts (Article 101-B).', [
                        'date' => $endsOn->format(Language::resolve(app()->getLocale())->dateFormat()),
                    ]),
                $settingsUrl,
                'info',
            );
        }

        if ($this->settings->isIncomplete()) {
            $notes[] = new TaxObligation(
                'incomplete-settings',
                'incomplete-settings',
                __('Complete your tax settings'),
                null,
                null,
                null,
                __('Enter when and how you started your activity to see Social Security and personal income tax information.'),
                $this->url(fn (Account $account) => FinanceSettingsPage::getUrl(panel: 'admin', tenant: $account)),
                'info',
            );
        }

        if (($threshold = $this->vatThreshold()) !== null) {
            $year = $this->today->year;
            $notes[] = new TaxObligation(
                'vat-threshold:'.$year,
                'vat-threshold',
                $threshold['label'],
                $threshold['amount'],
                null,
                null,
                trim(($threshold['usesReceipts'] ? __('VAT exemption threshold · estimate based on payments received.').' ' : '').$threshold['detail']
                    .' '.($threshold['notice'] ?? $threshold['advisory'] ?? '')),
                $this->territorialReviewUrl($year),
                'info',
            );

            if ($threshold['notice'] !== null) {
                $notes[] = new TaxObligation(
                    'vat-threshold-alert:'.$year,
                    'vat-threshold-alert',
                    __('VAT threshold exceeded'),
                    null,
                    null,
                    null,
                    $threshold['notice'],
                    $this->territorialReviewUrl($year),
                    'info',
                );
            }

            $previous = TerritorialTurnover::forYear($year - 1, $this->today);
            if (($notice = $previous->nextYearNotice()) !== null) {
                $notes[] = new TaxObligation(
                    'vat-threshold-previous:'.($year - 1),
                    'vat-threshold-previous',
                    __('VAT threshold exceeded in the previous year'),
                    $previous->subtotal(),
                    null,
                    null,
                    $notice.' '.$previous->coverageLabel().'.',
                    $this->territorialReviewUrl($year - 1),
                    'info',
                );
            }
        }

        foreach ((new ReverseChargeVat($this->settings))->foreignWithVat($this->month->subMonth()) as $expense) {
            /** @var Expense $expense */
            $notes[] = new TaxObligation(
                'foreign-vat-warning:'.$expense->getKey(),
                'foreign-vat-warning',
                __('Review expense from a foreign supplier that includes VAT'),
                null,
                null,
                null,
                __(':supplier · :description (:date): no reverse charge because the invoice includes VAT', [
                    'supplier' => $expense->supplier?->name ?? '—',
                    'description' => $expense->description,
                    'date' => $expense->spent_on->format(Language::resolve(app()->getLocale())->dateFormat()),
                ]),
                $this->url(fn (Account $account) => ExpenseResource::getUrl('view', ['record' => $expense], panel: 'admin', tenant: $account)),
                'info',
            );
        }

        $review = (new EuServicesRecap)->review($this->trackedFrom()->subMonths(3), $this->today);
        $details = array_filter([
            $review['documents'] > 0 ? trans_choice('{1} :count document without evidence of the operation|[2,*] :count documents without evidence of the operation', $review['documents']) : null,
            $review['editedDocuments'] > 0 ? trans_choice('{1} :count document edited after evidence was recorded|[2,*] :count documents edited after evidence was recorded', $review['editedDocuments']) : null,
            $review['payments'] > 0 ? trans_choice('{1} :count payment to confirm|[2,*] :count payments to confirm', $review['payments']) : null,
            $review['unknownFrequency'] > 0 ? trans_choice('{1} :count operation without a confirmed reporting frequency|[2,*] :count operations without a confirmed reporting frequency', $review['unknownFrequency']) : null,
        ]);
        if ($details !== []) {
            $notes[] = new TaxObligation(
                'recapitulativa-review',
                'recapitulativa-review',
                __('Recapitulative statement to review'),
                null,
                null,
                null,
                implode(' · ', $details),
                $this->url(fn (Account $account): string => FiscalDocumentResource::getUrl('index', panel: 'admin', tenant: $account)),
                'info',
            );
        }

        return $notes;
    }

    /**
     * By deadline; an exempt SS month (no deadline) sits where its payment would be.
     *
     * @param  array<int, TaxObligation>  $obligations
     * @return list<TaxObligation>
     */
    private function byDeadline(array $obligations): array
    {
        usort($obligations, fn (TaxObligation $a, TaxObligation $b) => $this->sortDate($a) <=> $this->sortDate($b));

        return $obligations;
    }

    private function sortDate(TaxObligation $obligation): string
    {
        return ($obligation->dueOn ?? CarbonImmutable::parse(substr($obligation->key, 3).'-20'))->toDateString();
    }

    private function status(string $key, CarbonImmutable $dueOn): string
    {
        return match (true) {
            $this->completions()->has($key) => 'done',
            $dueOn->lt($this->today) => 'overdue',
            default => 'due',
        };
    }

    /** @return Collection<string, TaxObligationCompletion> */
    private function completions(): Collection
    {
        return $this->completions ??= TaxObligationCompletion::query()->get()->keyBy('key');
    }

    private function estimator(): TaxEstimator
    {
        return $this->estimator ??= new TaxEstimator($this->settings, $this->today->year);
    }

    /**
     * Panel links need the account (tenant) in the URL: there is one in the panel and inside
     * CurrentAccount::run() (console); without one there is no link.
     *
     * @param  callable(Account): string  $build
     */
    private function url(callable $build): ?string
    {
        $account = CurrentAccount::get();

        return $account === null ? null : $build($account);
    }

    /** "novembro". */
    private static function monthName(CarbonImmutable $month): string
    {
        return Period::monthName($month->month, inSentence: true);
    }

    /** "jul–set" for the quarter starting in July. */
    private static function quarterName(CarbonImmutable $quarter): string
    {
        return Period::monthName($quarter->month, short: true, inSentence: true)
            .'–'.Period::monthName($quarter->addMonths(2)->month, short: true, inSentence: true);
    }
}
