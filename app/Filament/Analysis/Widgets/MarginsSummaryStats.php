<?php

namespace App\Filament\Analysis\Widgets;

use App\Filament\Analysis\Concerns\UsesMarginPeriod;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

/**
 * Margem total − custos de estrutura = resultado.
 */
class MarginsSummaryStats extends StatsOverviewWidget
{
    use HasSkeletonPlaceholder;
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
            Stat::make(__('Invoiced'), Money::format($totals['invoiced'], locale: app()->getLocale())),
            Stat::make(__('Margin'), Money::format($totals['margin'], locale: app()->getLocale()))
                ->valueColor($totals['margin'] > 0 ? 'success' : ($totals['margin'] < 0 ? 'danger' : null))
                ->description($totals['marginPercent'] === null ? __('No invoiced revenue') : __(':percent% of invoiced revenue', ['percent' => Number::format($totals['marginPercent'], precision: 1, locale: app()->getLocale())])),
            Stat::make(__('Overhead costs'), Money::format($totals['overhead'], locale: app()->getLocale()))
                ->description(__('Not part of the margin')),
            Stat::make(__('Result'), Money::format($totals['result'], locale: app()->getLocale()))
                ->description(__('Margin minus overhead'))
                ->valueColor($totals['result'] < 0 ? 'danger' : null),
        ];
    }
}
