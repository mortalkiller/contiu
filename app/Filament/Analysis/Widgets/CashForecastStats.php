<?php

namespace App\Filament\Analysis\Widgets;

use App\Filament\Analysis\Concerns\ForecastsMonths;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class CashForecastStats extends StatsOverviewWidget
{
    use ForecastsMonths;
    use HasSkeletonPlaceholder;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int|array
    {
        return ['md' => 4];
    }

    protected function getStats(): array
    {
        $forecast = $this->forecast();
        $totals = $forecast->totals();

        if (! $forecast->showsBalance()) {
            $worst = collect($forecast->months())->sortBy('net')->first();

            return [
                Stat::make(__('Expected inflows'), Money::format($totals['inflows'], locale: app()->getLocale()))->icon(Phosphor::HandCoins),
                Stat::make(__('Expected outflows'), Money::format($totals['outflows'], locale: app()->getLocale()))->icon(Phosphor::Receipt),
                Stat::make(__('Expected difference'), Money::format($totals['net'], locale: app()->getLocale()))
                    ->description(__('Inflows minus outflows for the period'))
                    ->color($totals['net'] < 0 ? 'danger' : null)
                    ->icon(Phosphor::Scales),
                Stat::make(__('Worst month'), Money::format($worst['net'] ?? 0, locale: app()->getLocale()))
                    ->description($worst['label'] ?? '')
                    ->color(($worst['net'] ?? 0) < 0 ? 'danger' : 'success')
                    ->icon(Phosphor::TrendDown),
            ];
        }

        return [
            Stat::make(__('Expected inflows'), Money::format($totals['inflows'], locale: app()->getLocale()))->icon(Phosphor::HandCoins),
            Stat::make(__('Expected outflows'), Money::format($totals['outflows'], locale: app()->getLocale()))->icon(Phosphor::Receipt),
            Stat::make(__('Closing balance'), Money::format($totals['endBalance'], locale: app()->getLocale()))
                ->description($forecast->startsFromBankBalance() ? __('Starting from the statement balance') : __('Starting from €0'))
                ->color($totals['endBalance'] < 0 ? 'danger' : null)
                ->icon(Phosphor::Wallet),
            Stat::make(__('Lowest balance'), Money::format($totals['lowestBalance'], locale: app()->getLocale()))
                ->description($totals['lowestBalance'] < 0 ? __('The balance turns negative') : __('The balance never turns negative'))
                ->descriptionIcon($totals['lowestBalance'] < 0 ? Phosphor::WarningCircle : Phosphor::CheckCircle)
                ->color($totals['lowestBalance'] < 0 ? 'danger' : 'success')
                ->icon(Phosphor::TrendDown),
        ];
    }
}
