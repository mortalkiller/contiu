<?php

namespace App\Filament\Analysis\Widgets;

use App\Filament\Analysis\Concerns\ForecastsMonths;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

class CashForecastStats extends StatsOverviewWidget
{
    use ForecastsMonths;
    use HasSkeletonPlaceholder;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int|array
    {
        return ['md' => 4];
    }

    /**
     * As in the mock: what comes in (in green), what goes out, then the balance at the end and at
     * its lowest, each with a line saying where the figure comes from.
     */
    protected function getStats(): array
    {
        $forecast = $this->forecast();
        $totals = $forecast->totals();
        $rows = $forecast->months();
        $locale = app()->getLocale();
        $first = reset($rows);
        $last = end($rows);
        $socialSecurity = (int) array_sum(array_column($rows, 'socialSecurity'));

        $inflows = Stat::make(__('Expected inflows'), Money::format($totals['inflows'], locale: $locale))
            ->description($first === false ? null : __(':from to :to', ['from' => $first['label'], 'to' => $last['label']]))
            ->valueColor($totals['inflows'] > 0 ? 'success' : null);
        $outflows = Stat::make(__('Expected outflows'), Money::format($totals['outflows'], locale: $locale))
            ->description($socialSecurity > 0 ? __('Includes :amount of Social Security', ['amount' => Money::format($socialSecurity, locale: $locale)]) : null);

        if (! $forecast->showsBalance()) {
            $worst = collect($rows)->sortBy('net')->first();

            return [
                $inflows,
                $outflows,
                Stat::make(__('Expected difference'), Money::format($totals['net'], locale: $locale))
                    ->description(__('Inflows minus outflows for the period'))
                    ->valueColor($totals['net'] < 0 ? 'danger' : null),
                Stat::make(__('Worst month'), Money::format($worst['net'] ?? 0, locale: $locale))
                    ->description($worst['label'] ?? '')
                    ->valueColor(($worst['net'] ?? 0) < 0 ? 'danger' : null),
            ];
        }

        $lowest = collect($rows)->sortBy('balance')->first();

        return [
            $inflows,
            $outflows,
            Stat::make(__('Closing balance'), Money::format($totals['endBalance'], locale: $locale))
                ->description($forecast->startsFromBankBalance()
                    ? __('Starts at :amount, the statement balance', ['amount' => Money::format($forecast->startingBalance(), locale: $locale)])
                    : __('Starting from €0'))
                ->valueColor($totals['endBalance'] < 0 ? 'danger' : null),
            Stat::make(__('Lowest balance'), Money::format($totals['lowestBalance'], locale: $locale))
                ->description(trim(($lowest === null ? '' : __('In :month', ['month' => $lowest['label']]).' · ')
                    .($totals['lowestBalance'] < 0 ? __('The balance turns negative') : __('The balance never turns negative')), ' ·'))
                ->valueColor($totals['lowestBalance'] < 0 ? 'danger' : null),
        ];
    }
}
