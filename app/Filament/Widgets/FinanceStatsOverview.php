<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesDashboardPeriod;
use App\Support\Money;
use App\Support\PeriodSummary;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * The four headline figures of the selected period, each compared with the previous period.
 * What is still to receive has its own section on the dashboard ("A receber").
 */
class FinanceStatsOverview extends StatsOverviewWidget
{
    use HasSkeletonPlaceholder;
    use UsesDashboardPeriod;

    protected static ?int $sort = 1;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int|array
    {
        return ['md' => 2, 'xl' => 4];
    }

    protected function getStats(): array
    {
        $summary = $this->summary();
        $label = $summary->period->label();

        return [
            $this->compared(Stat::make(__('Contracted in :period', ['period' => $label]), Money::format($summary->contracted(), locale: app()->getLocale())), $summary, 'contracted')
                ->icon(Phosphor::Handshake),
            $this->compared(Stat::make(__('Received in :period', ['period' => $label]), Money::format($summary->received(), locale: app()->getLocale())), $summary, 'received')
                ->icon(Phosphor::HandCoins),
            $this->compared(Stat::make(__('Expenses paid'), Money::format($summary->paidExpenses(), locale: app()->getLocale())), $summary, 'paidExpenses', higherIsBetter: false)
                ->icon(Phosphor::Receipt),
            $this->compared(Stat::make(__('Cash result'), Money::format($summary->cashResult(), locale: app()->getLocale())), $summary, 'cashResult')
                ->icon(Phosphor::Scales),
        ];
    }

    private function compared(Stat $stat, PeriodSummary $summary, string $figure, bool $higherIsBetter = true): Stat
    {
        $change = $summary->change($figure);
        $better = $change->isUp() === $higherIsBetter;

        return $stat
            ->description($change->percent === null
                ? __('no value in :period', ['period' => $summary->period->previous()->label()])
                : $change->label().' vs '.$summary->period->previous()->label())
            ->descriptionIcon(match (true) {
                $change->percent === null, $change->isFlat() => Phosphor::Minus,
                $change->isUp() => Phosphor::TrendUp,
                default => Phosphor::TrendDown,
            })
            ->color(match (true) {
                $change->percent === null, $change->isFlat() => 'gray',
                $better => 'success',
                default => 'danger',
            });
    }
}
