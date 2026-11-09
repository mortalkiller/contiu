<?php

namespace App\Filament\Widgets;

use App\Exceptions\AccountIsReadOnly;
use App\Models\User;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;
use App\Support\Money;
use App\Support\Period;
use App\Support\TaxEstimator;
use App\Support\TaxObligation;
use App\Support\TaxObligations;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Contracts\View\View;
use MortalKiller\FilamentSkeletonLoading\SkeletonLoadingPlugin;

/**
 * "Este mês": what there is to pay or deliver this month (always the current month, whatever
 * period the dashboard filters show), each row marked as paid / delivered from here. One line per
 * task; the details (which purchases, which article, how it is worked out) sit in tooltips.
 * ESTIMATES ONLY — see App\Support\TaxObligations.
 */
class TaxObligationsCard extends Widget
{
    protected static ?int $sort = 3;

    protected static bool $isLazy = true;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.tax-obligations-card';

    /** "Ver feitas": the rows already marked, each with "Desfazer". */
    public bool $showDone = false;

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

    /** Only a row the card lists as due or overdue can be marked. */
    public function complete(string $key): void
    {
        if ($this->refusedWhileReadOnly()) {
            return;
        }

        $obligations = $this->obligations();

        if (in_array($key, array_column($obligations->pending(), 'key'), true)) {
            $user = auth()->user();
            $obligations->complete($key, $user instanceof User ? $user : null);
        }
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
        $rows = $obligations->thisMonth();
        $pending = array_values(array_filter($rows, fn (TaxObligation $obligation) => in_array($obligation->status, ['due', 'overdue'], true)));
        $notes = array_values(array_filter($rows, fn (TaxObligation $obligation) => in_array($obligation->kind, ['incomplete-settings', 'foreign-vat-warning'], true)));
        $next = collect($pending)->filter(fn (TaxObligation $obligation) => $obligation->dueOn !== null)->sortBy(fn (TaxObligation $obligation) => $obligation->dueOn)->first();

        return [
            'month' => Period::MONTHS[$today->month - 1],
            'pendingCount' => count($pending),
            'pendingTotal' => Money::format((int) array_sum(array_map(fn (TaxObligation $obligation) => $obligation->amount ?? 0, $pending))),
            'next' => $next === null ? null : self::shortDate($next->dueOn).' · '.self::daysLabel($next->daysLeft($today)),
            'socialSecurity' => $this->socialSecurityLine($rows),
            'tasks' => array_map(fn (TaxObligation $obligation) => $this->row($obligation, $today), [...$pending, ...$notes]),
            'nothingToPay' => $pending === [] && ! $this->settings()->isIncomplete(),
            'limits' => array_values(array_filter([$this->socialSecurityLimit($today), $this->vatLimit($today)])),
            'upcoming' => implode(' · ', array_map(fn (TaxObligation $obligation) => $this->upcomingLabel($obligation), $obligations->upcoming())),
            'done' => array_map(fn (TaxObligation $obligation) => $this->row($obligation, $today), $obligations->done()),
            'readOnly' => CurrentAccount::readOnly(),
        ];
    }

    /**
     * One line of the card: the title (a link when there is one, the detail in its tooltip), the
     * deadline, the amount and the button.
     *
     * @return array{key: string, title: string, tooltip: string, url: ?string, when: ?string, overdue: bool, amount: ?string, mark: ?string, markTooltip: ?string, tone: string, note: bool}
     */
    private function row(TaxObligation $obligation, CarbonImmutable $today): array
    {
        $days = $obligation->daysLeft($today);
        [$mark, $markTooltip] = in_array($obligation->status, ['due', 'overdue'], true) ? match ($obligation->kind) {
            'ss', 'vat-reverse' => ['Pago', 'Marcar como pago'],
            'ss-declaration', 'recapitulativa', 'irs-modelo3' => ['Entregue', 'Marcar como entregue'],
            default => [null, null],
        } : [null, null];

        return [
            'key' => $obligation->key,
            'title' => $obligation->title.self::monthOf($obligation),
            'tooltip' => $obligation->detail,
            'url' => $obligation->url,
            'when' => match (true) {
                $obligation->status === 'done' => 'feito',
                $obligation->dueOn === null => null,
                $days < 0 => 'atrasado '.self::days(-$days),
                $days === 0 => 'termina hoje',
                default => 'até '.self::shortDate($obligation->dueOn),
            },
            'overdue' => $obligation->status === 'overdue',
            'amount' => $obligation->amount === null ? null : Money::format($obligation->amount),
            'mark' => $mark,
            'markTooltip' => $markTooltip,
            // The dot: overdue, due within a week (or a note to check), or later.
            'tone' => match (true) {
                $obligation->status === 'done' => 'success',
                $obligation->status === 'overdue' => 'danger',
                $obligation->status === 'info' => 'warning',
                $days !== null && $days <= 7 => 'warning',
                default => 'gray',
            },
            'note' => $obligation->status === 'info',
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
            $ss->status === 'exempt' => 'Segurança Social: isento',
            $ss->status === 'done' => 'Segurança Social: paga',
            default => 'Segurança Social: '.Money::format((int) $ss->amount).' até dia '.$ss->dueOn?->day,
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
        $months = mb_strtolower(Period::SHORT_MONTHS[$quarter->month - 1]).'–'.mb_strtolower(Period::SHORT_MONTHS[$quarter->month + 1]);

        return [
            'label' => 'Isenção da SS',
            'percent' => self::percent($percent),
            'width' => min(100.0, $percent),
            'color' => $percent >= 100 ? 'danger' : ($percent >= 80 ? 'warning' : 'primary'),
            'tooltip' => implode(' ', array_filter([
                'Rendimento relevante de '.$months.': '.Money::format($monthly).' de '.Money::format($limit).'/mês (4 × IAS, art. 157.º).',
                match (true) {
                    $percent >= 100 => 'Passaste o limite da isenção por acumulação: declara os rendimentos na próxima declaração trimestral e passas a contribuir.',
                    $percent >= 80 => 'Estás perto do limite da isenção por acumulação.',
                    default => null,
                },
            ])),
        ];
    }

    /** @return array{label: string, percent: string, width: float, color: string, tooltip: string}|null */
    private function vatLimit(CarbonImmutable $today): ?array
    {
        if (! $this->settings()->vatExempt()) {
            return null;
        }

        $estimator = new TaxEstimator($this->settings(), $today->year);
        $percent = $estimator->vatThresholdPercent();
        $status = $estimator->vatThresholdStatus();

        return [
            'label' => 'Isenção de IVA',
            'percent' => self::percent($percent),
            'width' => min(100.0, $percent),
            'color' => match ($status) {
                'ok' => 'primary',
                'warning' => 'warning',
                default => 'danger',
            },
            'tooltip' => implode(' ', array_filter([
                Money::format($estimator->income()).' de '.Money::format($this->settings()->vatExemptionThreshold()).' em '.$today->year.' (art. 53.º).',
                match ($status) {
                    'critical' => 'Passaste 125 % do limite: tens 15 dias úteis para a declaração de alterações.',
                    'danger' => 'Passaste o limite: sais da isenção de IVA no próximo ano (declaração em janeiro).',
                    'warning' => 'Estás perto do limite de isenção de IVA.',
                    default => null,
                },
            ])),
        ];
    }

    /** "Declaração trimestral da SS · até 31 jan" · "IRS com anexo SS · abr–jun". */
    private function upcomingLabel(TaxObligation $obligation): string
    {
        $title = str_replace(['IRS (modelo 3)', 'Segurança Social'], ['IRS com anexo SS', 'SS'], $obligation->title);

        return match (true) {
            $obligation->dueOn === null => $title,
            $obligation->opensOn !== null => $title.' · '.mb_strtolower(Period::SHORT_MONTHS[$obligation->opensOn->month - 1]).'–'.mb_strtolower(Period::SHORT_MONTHS[$obligation->dueOn->month - 1]),
            default => $title.' · até '.self::shortDate($obligation->dueOn),
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

        if (! in_array($obligation->kind, ['ss', 'vat-reverse', 'recapitulativa'], true)) {
            return '';
        }

        $month = (int) substr($obligation->key, -2);

        return ' · '.mb_strtolower(Period::MONTHS[$month - 1]);
    }

    /** "30 nov". */
    private static function shortDate(CarbonImmutable $date): string
    {
        return $date->day.' '.mb_strtolower(Period::SHORT_MONTHS[$date->month - 1]);
    }

    /** "21 dias" · "hoje" · "atrasado 5 dias". */
    private static function daysLabel(?int $days): string
    {
        return match (true) {
            $days === null => '',
            $days < 0 => 'atrasado '.self::days(-$days),
            $days === 0 => 'hoje',
            default => self::days($days),
        };
    }

    private static function percent(float $percent): string
    {
        return number_format($percent, 1, ',', ' ').' %';
    }

    private static function days(int $days): string
    {
        return $days === 1 ? '1 dia' : $days.' dias';
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
