<?php

namespace App\Filament\Widgets;

use App\Actions\RecordTaxPayment;
use App\Exceptions\AccountIsReadOnly;
use App\Filament\Actions\RecordPaymentAction;
use App\Filament\Pages\Calendar;
use App\Models\Receivable;
use App\Models\User;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;
use App\Support\Money;
use App\Support\PendingWork;
use App\Support\Period;
use App\Support\TaxEstimator;
use App\Support\TaxObligation;
use App\Support\TaxObligations;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Widgets\Widget;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use MortalKiller\FilamentSkeletonLoading\SkeletonLoadingPlugin;

/**
 * "Por tratar": what needs doing now, whatever period the dashboard filters show. The coloured
 * block sums what is owed to the State this month; the list has the tax obligations first (each
 * marked as paid / delivered from here), then the statement lines to reconcile and the amounts
 * clients owe (App\Support\PendingWork). One line per task; the details sit in tooltips.
 * ESTIMATES ONLY — see App\Support\TaxObligations.
 */
class TaxObligationsCard extends Widget implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    /** The list shows this many rows; the rest open with "Ver mais". */
    public const int VISIBLE_ROWS = 3;

    private const int LOCAL_ROW_LIMIT = 12;

    protected static ?int $sort = 3;

    protected static bool $isLazy = true;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.tax-obligations-card';

    /** "Ver feitas": the rows already marked, each with "Desfazer". */
    public bool $showDone = false;

    /** "Ver mais": every row instead of the first few. */
    public bool $showAll = false;

    private ?FinanceSettings $settings = null;

    /** While it loads: a skeleton with the card's own shape (summary block, a task, two limits). */
    public function placeholder(): View
    {
        if (! SkeletonLoadingPlugin::isEnabled()) {
            return parent::placeholder();
        }

        return view('filament.widgets.partials.tax-obligations-card-skeleton');
    }

    public function toggleDone(): void
    {
        $this->showDone = ! $this->showDone;
    }

    public function toggleAll(): void
    {
        $this->showAll = ! $this->showAll;
    }

    /** "Receber" on a client's row: the same modal as everywhere else, for that amount. */
    public function receiveAction(): Action
    {
        return RecordPaymentAction::make('receive')
            ->record(fn (array $arguments): ?Receivable => Receivable::query()->find($arguments['receivable'] ?? null))
            ->label(__('Receive payment'))
            ->icon(null)
            ->color('gray')
            ->button()
            ->size('xs');
    }

    /**
     * Only a row the card lists as due or overdue can be marked. With $withExpense, a payment (the
     * VAT, the Segurança Social) is also recorded as a paid expense, so the bank statement matches.
     */
    public function complete(string $key, bool $withExpense = false): void
    {
        if ($this->refusedWhileReadOnly()) {
            return;
        }

        $obligations = $this->obligations();
        $obligation = collect($obligations->pending())->firstWhere('key', $key);

        if (! $obligation instanceof TaxObligation) {
            return;
        }

        DB::transaction(function () use ($obligations, $obligation, $withExpense): void {
            if ($withExpense && RecordTaxPayment::applies($obligation)) {
                app(RecordTaxPayment::class)->handle($obligation);
            }

            $user = auth()->user();
            $obligations->complete($obligation->key, $user instanceof User ? $user : null);
        });
    }

    /** Only a row the card lists under "Ver feitas" can be reopened. */
    public function reopen(string $key): void
    {
        if ($this->refusedWhileReadOnly()) {
            return;
        }

        $obligations = $this->obligations();

        if (in_array($key, array_column($obligations->done(), 'key'), true)) {
            $obligations->reopen($key);
        }
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $today = CarbonImmutable::today();
        $obligations = $this->obligations();
        ['rows' => $rows, 'done' => $done] = $obligations->monthOverview();
        $pending = array_values(array_filter($rows, fn (TaxObligation $obligation) => in_array($obligation->status, ['due', 'overdue'], true)));
        $notes = array_values(array_filter($rows, fn (TaxObligation $obligation) => in_array($obligation->kind, ['incomplete-settings', 'foreign-vat-warning', 'vat-threshold-alert', 'vat-threshold-previous'], true)));
        $next = collect($pending)->filter(fn (TaxObligation $obligation) => $obligation->dueOn !== null)->sortBy(fn (TaxObligation $obligation) => $obligation->dueOn)->first();

        $otherTasks = (new PendingWork($this->settings(), $today))->rows();
        $tasks = [
            ...array_map(fn (TaxObligation $obligation) => $this->row($obligation, $today), [...$pending, ...$notes]),
            ...$otherTasks,
        ];
        $localTaskToggle = count($tasks) <= self::LOCAL_ROW_LIMIT;
        $localDoneToggle = count($done) <= self::LOCAL_ROW_LIMIT;

        return [
            'month' => Period::monthName($today->month, inSentence: true),
            'pendingCount' => count($pending),
            'taskCount' => count($pending) + count($otherTasks),
            'pendingTotal' => Money::format((int) array_sum(array_map(fn (TaxObligation $obligation) => $obligation->amount ?? 0, $pending)), locale: app()->getLocale()),
            'next' => $next === null ? null : self::shortDate($next->dueOn).' · '.self::daysLabel($next->daysLeft($today)),
            'socialSecurity' => $this->socialSecurityLine($rows),
            'tasks' => array_slice($tasks, 0, self::VISIBLE_ROWS),
            'extraTasks' => $localTaskToggle || $this->showAll ? array_slice($tasks, self::VISIBLE_ROWS) : [],
            'hiddenCount' => max(0, count($tasks) - self::VISIBLE_ROWS),
            'showAll' => $this->showAll,
            'localTaskToggle' => $localTaskToggle,
            'calendarUrl' => Calendar::getUrl(),
            'nothingToPay' => $pending === [] && ! $this->settings()->isIncomplete(),
            'limits' => array_values(array_filter([$this->socialSecurityLimit($today), $this->vatLimit($obligations)])),
            'upcoming' => implode(' · ', array_map(fn (TaxObligation $obligation) => $this->upcomingLabel($obligation), $obligations->upcoming())),
            'done' => $localDoneToggle || $this->showDone
                ? array_map(fn (TaxObligation $obligation) => $this->row($obligation, $today), $done)
                : [],
            'doneCount' => count($done),
            'localDoneToggle' => $localDoneToggle,
            'readOnly' => CurrentAccount::readOnly(),
        ];
    }

    /**
     * One line of the card: the title (a link when there is one, the detail in its tooltip), the
     * deadline, the amount and the button.
     *
     * @return array{key: string, title: string, tooltip: string, url: ?string, when: ?string, overdue: bool, amount: ?string, mark: ?string, markTooltip: ?string, asksExpense: bool, tone: string, note: bool, link: ?string, receivable: ?int}
     */
    private function row(TaxObligation $obligation, CarbonImmutable $today): array
    {
        $days = $obligation->daysLeft($today);
        [$mark, $markTooltip] = in_array($obligation->status, ['due', 'overdue'], true) ? match ($obligation->kind) {
            'ss', 'vat-reverse', 'irs-ppc' => [__('Paid'), __('Mark as paid')],
            'ss-declaration', 'ss-annual', 'vat-return', 'recapitulativa', 'irs-modelo3' => [__('Filed'), __('Mark as filed')],
            default => [null, null],
        } : [null, null];

        return [
            'key' => $obligation->key,
            'title' => $obligation->title.self::monthOf($obligation),
            'tooltip' => $obligation->detail,
            'url' => $obligation->url,
            'when' => match (true) {
                $obligation->status === 'done' => __('done'),
                $obligation->dueOn === null => null,
                $days < 0 => __('overdue :days', ['days' => self::days(-$days)]),
                $days === 0 => __('ends today'),
                default => __('by :date', ['date' => self::shortDate($obligation->dueOn)]),
            },
            'overdue' => $obligation->status === 'overdue',
            'amount' => $obligation->amount === null ? null : Money::format($obligation->amount, locale: app()->getLocale()),
            'mark' => $mark,
            'markTooltip' => $markTooltip,
            // A payment can also be recorded as an expense: the button asks first.
            'asksExpense' => $mark !== null && RecordTaxPayment::applies($obligation),
            // The dot: overdue, due within a week (or a note to check), or later.
            'tone' => match (true) {
                $obligation->status === 'done' => 'success',
                $obligation->status === 'overdue', $obligation->kind === 'vat-threshold-alert' => 'danger',
                $obligation->status === 'info' => 'warning',
                $days !== null && $days <= 7 => 'warning',
                default => 'gray',
            },
            'note' => $obligation->status === 'info',
            'link' => null,
            'receivable' => null,
        ];
    }

    /**
     * The month's Segurança Social in a few words for the summary block.
     *
     * @param  list<TaxObligation>  $rows
     */
    private function socialSecurityLine(array $rows): ?string
    {
        $ss = collect($rows)->firstWhere('kind', 'ss');

        return match (true) {
            $ss === null => null,
            $ss->status === 'exempt' => __('Social Security: exempt'),
            $ss->status === 'done' => __('Social Security: paid'),
            default => __('Social Security: :amount by day :day', ['amount' => Money::format((int) $ss->amount, locale: app()->getLocale()), 'day' => $ss->dueOn?->day]),
        };
    }

    /**
     * The accumulation exemption (art. 157.º, n.º 1, al. a)) holds while the relevant income of the
     * last closed quarter stays under 4 × IAS a month. Only for an account exempt from contributing.
     *
     * @return array{label: string, percent: string, width: float, color: string, tooltip: string}|null
     */
    private function socialSecurityLimit(CarbonImmutable $today): ?array
    {
        if (! $this->settings()->ssContributionExempt() || $this->settings()->isIncomplete()) {
            return null;
        }

        $quarter = $today->firstOfQuarter()->subMonths(3);
        $estimator = new TaxEstimator($this->settings(), $quarter->year);
        $monthly = $estimator->ssRelevantMonthlyIncome($quarter);
        $limit = $estimator->ssAccumulationLimit();
        $percent = $limit > 0 ? round($monthly / $limit * 100, 1) : 0.0;
        $months = Period::monthName($quarter->month, short: true, inSentence: true).'–'.Period::monthName($quarter->month + 2, short: true, inSentence: true);

        return [
            'label' => __('Social Security exemption'),
            'percent' => self::percent($percent),
            'width' => min(100.0, $percent),
            'color' => $percent >= 100 ? 'danger' : ($percent >= 80 ? 'warning' : 'primary'),
            'tooltip' => implode(' ', array_filter([
                __('Relevant income for :months: :income out of :limit/month (4 × IAS, Article 157).', ['months' => $months, 'income' => Money::format($monthly, locale: app()->getLocale()), 'limit' => Money::format($limit, locale: app()->getLocale())]),
                match (true) {
                    $percent >= 100 => __('You exceeded the exemption threshold for concurrent employment. Report the income in your next quarterly declaration and start contributing.'),
                    $percent >= 80 => __('You are close to the exemption threshold for concurrent employment.'),
                    default => null,
                },
            ])),
        ];
    }

    /** @return array{label: string, percent: string, width: float, color: string, tooltip: string}|null */
    private function vatLimit(TaxObligations $obligations): ?array
    {
        $threshold = $obligations->vatThreshold();
        if ($threshold === null) {
            return null;
        }

        return [
            'label' => $threshold['label'],
            'percent' => self::percent($threshold['percent']),
            'width' => max(0.0, min(100.0, $threshold['percent'])),
            'color' => $threshold['status'] === 'ok' ? 'primary' : $threshold['status'],
            'tooltip' => trim(($threshold['usesReceipts'] ? __('Approximate based on payments received. ') : '').$threshold['detail']
                .' '.($threshold['notice'] ?? $threshold['advisory'] ?? '')),
        ];
    }

    /** An upcoming obligation with its deadline or opening window. */
    private function upcomingLabel(TaxObligation $obligation): string
    {
        $title = match ($obligation->kind) {
            'irs-modelo3' => __('Personal income tax with Social Security schedule'),
            'ss' => __('SS'),
            default => $obligation->title,
        };

        return match (true) {
            $obligation->dueOn === null => $title,
            $obligation->opensOn !== null => $title.' · '.Period::monthName($obligation->opensOn->month, short: true, inSentence: true).'–'.Period::monthName($obligation->dueOn->month, short: true, inSentence: true),
            default => __(':title · by :date', ['title' => $title, 'date' => self::shortDate($obligation->dueOn)]),
        };
    }

    /**
     * " · abril" for a monthly obligation, so several months of the same one can be told apart: the
     * month of the purchases (IVA), of the sales (recapitulativa) or of the payment (SS); " · 2025"
     * for the IRS return of that year's income.
     */
    private static function monthOf(TaxObligation $obligation): string
    {
        if ($obligation->kind === 'irs-modelo3') {
            return ' · '.substr($obligation->key, -4);
        }

        if (in_array($obligation->kind, ['vat-return', 'ss-annual', 'irs-ppc'], true)) {
            return ' · '.$obligation->period();
        }

        if (! in_array($obligation->kind, ['ss', 'vat-reverse', 'recapitulativa'], true)) {
            return '';
        }

        $month = (int) substr($obligation->key, -2);

        return ' · '.Period::monthName($month, inSentence: true);
    }

    /** "30 nov". */
    private static function shortDate(CarbonImmutable $date): string
    {
        return __(':month :day', ['month' => Period::monthName($date->month, short: true, inSentence: true), 'day' => $date->day]);
    }

    /** "21 dias" · "hoje" · "atrasado 5 dias". */
    private static function daysLabel(?int $days): string
    {
        return match (true) {
            $days === null => '',
            $days < 0 => __('overdue :days', ['days' => self::days(-$days)]),
            $days === 0 => __('today'),
            default => self::days($days),
        };
    }

    private static function percent(float $percent): string
    {
        return Number::format($percent, precision: 1, locale: app()->getLocale()).' %';
    }

    private static function days(int $days): string
    {
        return trans_choice('{1} 1 day|[2,*] :count days', $days);
    }

    private function refusedWhileReadOnly(): bool
    {
        if (! CurrentAccount::readOnly()) {
            return false;
        }

        Notification::make()->title((new AccountIsReadOnly)->getMessage())->danger()->send();

        return true;
    }

    private function obligations(): TaxObligations
    {
        return new TaxObligations($this->settings(), CarbonImmutable::today());
    }

    private function settings(): FinanceSettings
    {
        return $this->settings ??= app(FinanceSettings::class);
    }
}
