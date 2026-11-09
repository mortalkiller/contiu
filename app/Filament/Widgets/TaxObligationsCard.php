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
        $pending = array_filter($rows, fn (TaxObligation $obligation) => in_array($obligation->status, ['due', 'overdue'], true));

        return [
            'month' => mb_strtolower(Period::MONTHS[$today->month - 1]).' '.$today->year,
            'pendingCount' => count($pending),
            'pendingTotal' => array_sum(array_map(fn (TaxObligation $obligation) => $obligation->amount ?? 0, $pending)),
            'rows' => array_map(fn (TaxObligation $obligation) => $this->row($obligation, $today), $rows),
            'nothingToPay' => $pending === [] && ! $this->settings()->isIncomplete(),
            'upcoming' => array_map(fn (TaxObligation $obligation) => $this->upcomingLabel($obligation), $obligations->upcoming()),
            'done' => array_map(fn (TaxObligation $obligation) => $this->row($obligation, $today), $obligations->done()),
            'readOnly' => CurrentAccount::readOnly(),
        ];
    }

    /**
     * What the view draws for one obligation.
     *
     * @return array{key: string, kind: string, title: string, amount: ?string, detail: string, badge: ?array{label: string, color: string}, mark: ?string, link: ?array{label: string, url: string}, threshold: ?array{percent: string, width: float, color: string}}
     */
    private function row(TaxObligation $obligation, CarbonImmutable $today): array
    {
        return [
            'key' => $obligation->key,
            'kind' => $obligation->kind,
            'title' => $obligation->title,
            'amount' => $obligation->amount === null || $obligation->kind === 'vat-threshold' ? null : Money::format($obligation->amount),
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
            'threshold' => $obligation->kind === 'vat-threshold' ? $this->threshold($today) : null,
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

    /** @return array{percent: string, width: float, color: string} */
    private function threshold(CarbonImmutable $today): array
    {
        $estimator = new TaxEstimator($this->settings(), $today->year);
        $percent = $estimator->vatThresholdPercent();

        return [
            'percent' => number_format($percent, 1, ',', ' ').' %',
            'width' => min(100.0, $percent),
            'color' => match ($estimator->vatThresholdStatus()) {
                'ok' => 'primary',
                'warning' => 'warning',
                default => 'danger',
            },
        ];
    }

    /** "Declaração trimestral da Segurança Social, até 31 jan" · "IRS (modelo 3), de 1 abr a 30 jun". */
    private function upcomingLabel(TaxObligation $obligation): string
    {
        $date = fn (CarbonImmutable $date) => $date->day.' '.mb_strtolower(Period::SHORT_MONTHS[$date->month - 1]);

        return match (true) {
            $obligation->dueOn === null => $obligation->title,
            $obligation->opensOn !== null => $obligation->title.', de '.$date($obligation->opensOn).' a '.$date($obligation->dueOn),
            default => $obligation->title.', até '.$date($obligation->dueOn),
        };
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
