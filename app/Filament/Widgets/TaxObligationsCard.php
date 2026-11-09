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
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

/**
 * "Este mês": what there is to pay or deliver this month (always the current month, whatever
 * period the dashboard filters show), each row marked as paid / delivered from here.
 * ESTIMATES ONLY — see App\Support\TaxObligations.
 */
class TaxObligationsCard extends Widget
{
    use HasSkeletonPlaceholder;

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.tax-obligations-card';

    /** "Ver feitas": the rows already marked, each with "Desfazer". */
    public bool $showDone = false;

    private ?FinanceSettings $settings = null;

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
            'monthName' => mb_strtolower(Period::MONTHS[$today->month - 1]),
            'pendingCount' => count($pending),
            'pendingTotal' => Money::format((int) array_sum(array_map(fn (TaxObligation $obligation) => $obligation->amount ?? 0, $pending))),
            'next' => $next === null ? null : [
                'title' => $next->title,
                'date' => $next->dueOn->day.' de '.mb_strtolower(Period::MONTHS[$next->dueOn->month - 1]),
                'badge' => $this->badge($next, $today),
            ],
            'socialSecurity' => $this->socialSecurityLine($rows),
            'tasks' => array_map(fn (TaxObligation $obligation) => $this->row($obligation, $today), [...$pending, ...$notes]),
            'nothingToPay' => $pending === [] && ! $this->settings()->isIncomplete(),
            'limits' => array_values(array_filter([$this->socialSecurityLimit($today), $this->vatLimit($today)])),
            'upcoming' => array_map(fn (TaxObligation $obligation) => ['title' => $obligation->title, 'when' => $this->upcomingWhen($obligation)], $obligations->upcoming()),
            'done' => array_map(fn (TaxObligation $obligation) => $this->row($obligation, $today), $obligations->done()),
            'readOnly' => CurrentAccount::readOnly(),
        ];
    }

    /**
     * What the view draws for one obligation.
     *
     * @return array{key: string, kind: string, title: string, amount: ?string, detail: string, badge: ?array{label: string, color: string}, mark: ?string, link: ?array{label: string, url: string}, tone: string}
     */
    private function row(TaxObligation $obligation, CarbonImmutable $today): array
    {
        $days = $obligation->daysLeft($today);

        return [
            'key' => $obligation->key,
            'kind' => $obligation->kind,
            'title' => $obligation->title,
            'amount' => $obligation->amount === null ? null : Money::format($obligation->amount),
            'detail' => $obligation->detail,
            'badge' => $this->badge($obligation, $today),
            'mark' => in_array($obligation->status, ['due', 'overdue'], true) ? match ($obligation->kind) {
                'ss', 'vat-reverse' => 'Marcar como pago',
                'ss-declaration', 'recapitulativa', 'irs-modelo3' => 'Marcar como entregue',
                default => null,
            } : null,
            'link' => $obligation->url === null ? null : [
                'label' => match ($obligation->kind) {
                    'vat-reverse' => 'Ver compras',
                    'foreign-vat-warning' => 'Ver despesa',
                    'incomplete-settings' => 'Completar definições',
                    default => 'Ver estimativa',
                },
                'url' => $obligation->url,
            ],
            // The dot beside the title: overdue, due within a week, a note, or later.
            'tone' => match (true) {
                $obligation->status === 'overdue' => 'danger',
                $obligation->status === 'info' => 'warning',
                $days !== null && $days <= 7 => 'warning',
                default => 'gray',
            },
        ];
    }

    /**
     * The month's Segurança Social in one line for the summary block.
     *
     * @param  list<TaxObligation>  $rows
     */
    private function socialSecurityLine(array $rows): ?string
    {
        $ss = collect($rows)->firstWhere('kind', 'ss');

        return match (true) {
            $ss === null => null,
            $ss->status === 'exempt' => 'Segurança Social: '.$ss->detail,
            $ss->status === 'done' => 'Segurança Social do mês paga',
            default => 'Segurança Social: '.Money::format((int) $ss->amount).' até dia '.$ss->dueOn?->day,
        };
    }

    /**
     * The accumulation exemption (art. 157.º, n.º 1, al. a)) holds while the relevant income of the
     * last closed quarter stays under 4 × IAS a month. Only for an account exempt from contributing.
     *
     * @return array{label: string, percent: string, width: float, color: string, detail: string, message: ?string}|null
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
            'label' => 'Isenção da Segurança Social',
            'percent' => self::percent($percent),
            'width' => min(100.0, $percent),
            'color' => $percent >= 100 ? 'danger' : ($percent >= 80 ? 'warning' : 'primary'),
            'detail' => Money::format($monthly).' de '.Money::format($limit).'/mês ('.$months.')',
            'message' => match (true) {
                $percent >= 100 => 'Passaste o limite da isenção por acumulação: declara os rendimentos na próxima declaração trimestral e passas a contribuir.',
                $percent >= 80 => 'Estás perto do limite da isenção por acumulação (4 × IAS por mês).',
                default => null,
            },
        ];
    }

    /** @return array{label: string, percent: string, width: float, color: string, detail: string, message: ?string}|null */
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
            'detail' => Money::format($estimator->income()).' de '.Money::format($this->settings()->vatExemptionThreshold()).' em '.$today->year,
            'message' => match ($status) {
                'critical' => 'Passaste 125 % do limite: tens 15 dias úteis para a declaração de alterações.',
                'danger' => 'Passaste o limite: sais da isenção de IVA no próximo ano (declaração em janeiro).',
                'warning' => 'Estás perto do limite de isenção de IVA.',
                default => null,
            },
        ];
    }

    /** @return array{label: string, color: string}|null */
    private function badge(TaxObligation $obligation, CarbonImmutable $today): ?array
    {
        $days = $obligation->daysLeft($today);

        return match (true) {
            $obligation->status === 'done' => ['label' => 'Feito', 'color' => 'success'],
            $obligation->status === 'exempt' => ['label' => 'Isento', 'color' => 'gray'],
            $days === null => null,
            $days < 0 => ['label' => 'Em atraso há '.self::days(-$days), 'color' => 'danger'],
            $days === 0 => ['label' => 'Termina hoje', 'color' => 'warning'],
            default => ['label' => ($days === 1 ? 'Falta ' : 'Faltam ').self::days($days), 'color' => $days <= 7 ? 'warning' : 'gray'],
        };
    }

    /** "até 31 jan" · "de 1 abr a 30 jun". */
    private function upcomingWhen(TaxObligation $obligation): ?string
    {
        $date = fn (CarbonImmutable $date) => $date->day.' '.mb_strtolower(Period::SHORT_MONTHS[$date->month - 1]);

        return match (true) {
            $obligation->dueOn === null => null,
            $obligation->opensOn !== null => 'de '.$date($obligation->opensOn).' a '.$date($obligation->dueOn),
            default => 'até '.$date($obligation->dueOn),
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
