<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Receivables\ReceivableResource;
use App\Filament\Widgets\Concerns\UsesDashboardPeriod;
use App\Support\Money;
use App\Support\PeriodSummary;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * Headline figures for the selected period, each compared with the previous period.
 */
class FinanceStatsOverview extends StatsOverviewWidget
{
    use UsesDashboardPeriod;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int|array
    {
        return ['md' => 3];
    }

    protected function getStats(): array
    {
        $summary = $this->summary();
        $label = $summary->period->label();
        $overdue = $summary->overdue();

        return [
            $this->compared(Stat::make('Contratado em '.$label, Money::format($summary->contracted())), $summary, 'contracted')
                ->icon(Phosphor::Handshake),
            $this->compared(Stat::make('Recebido em '.$label, Money::format($summary->received())), $summary, 'received')
                ->icon(Phosphor::HandCoins),
            $this->compared(Stat::make('Despesas pagas', Money::format($summary->paidExpenses())), $summary, 'paidExpenses', higherIsBetter: false)
                ->icon(Phosphor::Receipt),
            $this->compared(Stat::make('Resultado de caixa', Money::format($summary->cashResult())), $summary, 'cashResult')
                ->icon(Phosphor::Scales),
            Stat::make('Por receber', Money::format($summary->outstanding()))
                ->description($overdue > 0 ? 'Vencido: '.Money::format($overdue) : 'Nada vencido')
                ->descriptionIcon($overdue > 0 ? Phosphor::WarningCircle : Phosphor::CheckCircle)
                ->color($overdue > 0 ? 'danger' : 'success')
                ->icon(Phosphor::HourglassMedium)
                ->url(ReceivableResource::getUrl()),
            Stat::make('Próximos 30 dias', Money::format(PeriodSummary::dueNext30Days()))
                ->description('A vencer até '.today()->addDays(30)->format('d/m').' (a partir de hoje)')
                ->icon(Phosphor::CalendarBlank)
                ->url(ReceivableResource::getUrl(parameters: ['tab' => 'next30'])),
        ];
    }

    private function compared(Stat $stat, PeriodSummary $summary, string $figure, bool $higherIsBetter = true): Stat
    {
        $change = $summary->change($figure);
        $better = $change->isUp() === $higherIsBetter;

        return $stat
            ->description($change->percent === null
                ? 'sem valor em '.$summary->period->previous()->label()
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
