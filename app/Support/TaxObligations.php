<?php

namespace App\Support;

use App\Enums\Language;
use App\Filament\Pages\FinanceSettingsPage;
use App\Filament\Pages\SocialSecurity as SocialSecurityPage;
use App\Filament\Pages\TaxEstimate;
use App\Filament\Pages\TerritorialTurnoverPage;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Models\Account;
use App\Models\Expense;
use App\Models\TaxObligationCompletion;
use App\Models\User;
use App\Models\VatPeriodConfirmation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What the account has to pay or deliver, month by month (the "Este mês" card and its alerts).
 * ESTIMATES ONLY — the amounts come from TaxEstimator and ReverseChargeVat.
 *
 * Keys and deadlines:
 * - ss:YYYY-MM — Segurança Social paid in the month, between the 10th and the 20th: the contribution
 *   of the month before (art. 155.º);
 * - ss-declaration:YYYY-Qn — quarterly SS declaration, by the end of the month after the quarter;
 * - vat-reverse:YYYY-MM — VAT self-assessed on the month's foreign services, by the end of the next month;
 * - recapitulativa:operacoes:YYYY-MM or YYYY-Qn — verified EU services by operation date;
 * - irs-modelo3:YYYY — the year's IRS return, from 1 April to 30 June of the next year.
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
        if ($this->settings->isIncomplete()) {
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
        $previous = $month->subMonth();

        return [...array_values(array_filter([
            $this->settings->isIncomplete() ? null : $this->ss($month),
            $month->month % 3 === 1 ? $this->ssDeclaration($month->subMonths(3)) : null,
            $this->vatReverse($previous),
            $month->month === 6 ? $this->irsReturn($month->year - 1) : null,
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
        $status = $this->estimator()->ssPaymentStatus($month);

        if ($confirmed === 0 || ($confirmed === null && $status !== 'charged')) {
            $detail = match (true) {
                $confirmed === 0 => __('No contribution due · confirmed amount'),
                $status === 'exempt' => __('Exempt (Art. 157)'),
                default => __('Exempt (first enrollment)'),
            };

            return new TaxObligation($key, 'ss', __('Social Security'), null, null, null, $detail, $url, 'exempt');
        }

        // While the quarter of income is still open its figure would be partial (or the minimum).
        $amount = $confirmed ?? ($sourceClosed ? $this->estimator()->ssPaymentInMonth($month) : null);
        $detail = __('Pay between :month 10 and 20 · :contributionMonth contribution · income from :quarter', [
            'month' => self::monthName($month),
            'contributionMonth' => self::monthName($contributionMonth),
            'quarter' => self::quarterName($source),
        ]);
        $amountStatus = match (true) {
            $confirmed !== null => __('confirmed amount'),
            $amount === null => __('amount to be determined'),
            $amount === TaxEstimator::SS_MINIMUM_CONTRIBUTION => __('minimum contribution'),
            default => null,
        };
        if ($amountStatus !== null) {
            $detail .= ' · '.$amountStatus;
        }
        $dueOn = $month->setDay(20);

        return new TaxObligation($key, 'ss', __('Social Security'), $amount, $dueOn, $month->setDay(10), $detail, $url, $this->status($key, $dueOn));
    }

    /** Declared in the month after the quarter; not while the SS obligation has not started. */
    private function ssDeclaration(CarbonImmutable $quarter): ?TaxObligation
    {
        $dueOn = $quarter->addMonths(3)->endOfMonth()->startOfDay();

        // Only workers who must contribute declare (art. 151.º-A, n.º 1): not during the first
        // enrolment, nor with a contribution exemption such as the art. 157.º accumulation.
        if ($this->settings->isIncomplete() || $this->estimator()->ssMonthStatus($dueOn) !== 'charged') {
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

    /** Only for a month with foreign services to self-assess. */
    private function vatReverse(CarbonImmutable $month): ?TaxObligation
    {
        $vat = (new ReverseChargeVat($this->settings))->forMonth($month);

        if ($vat['vat'] <= 0) {
            return null;
        }

        $key = 'vat-reverse:'.$month->format('Y-m');
        $dueOn = $month->addMonth()->endOfMonth()->startOfDay();

        return new TaxObligation(
            $key,
            'vat-reverse',
            __('VAT on purchases from abroad'),
            $vat['vat'],
            $dueOn,
            null,
            __('File the periodic VAT return and pay by :dueMonth :day · :month purchases (:base excluding VAT)', [
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
        $dueOn = CarbonImmutable::create($year + 1, 6, 30);

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
                $this->url(fn (Account $account) => ExpenseResource::getUrl('edit', ['record' => $expense], panel: 'admin', tenant: $account)),
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
