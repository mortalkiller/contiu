<?php

namespace App\Filament\Analysis\Widgets;

use App\Filament\Analysis\Concerns\UsesMarginPeriod;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * Margem total − custos de estrutura = resultado.
 */
class MarginsSummaryStats extends StatsOverviewWidget
{
    use UsesMarginPeriod;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int|array
    {
        return ['md' => 4];
    }

    protected function getStats(): array
    {
        $totals = $this->report()->totals();

        return [
            Stat::make('Faturado', Money::format($totals['invoiced']))->icon(Phosphor::Handshake),
            Stat::make('Margem', Money::format($totals['margin']))
                ->description($totals['marginPercent'] === null ? 'Sem faturação' : number_format($totals['marginPercent'], 1, ',', ' ').'% do faturado')
                ->icon(Phosphor::ChartPie),
            Stat::make('Custos de estrutura', Money::format($totals['overhead']))
                ->description('Ferramentas, taxas e custos gerais')
                ->icon(Phosphor::Receipt),
            Stat::make('Resultado', Money::format($totals['result']))
                ->description('Margem − estrutura')
                ->color($totals['result'] < 0 ? 'danger' : 'success')
                ->icon(Phosphor::Scales),
        ];
    }
}
