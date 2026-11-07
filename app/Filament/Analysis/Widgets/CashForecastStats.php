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

        return [
            Stat::make('Entradas previstas', Money::format($totals['inflows']))->icon(Phosphor::HandCoins),
            Stat::make('Saídas previstas', Money::format($totals['outflows']))->icon(Phosphor::Receipt),
            Stat::make('Saldo no fim', Money::format($totals['endBalance']))
                ->description($forecast->startsFromBankBalance() ? 'A partir do saldo do extrato' : 'A partir de 0 €')
                ->color($totals['endBalance'] < 0 ? 'danger' : null)
                ->icon(Phosphor::Wallet),
            Stat::make('Saldo mais baixo', Money::format($totals['lowestBalance']))
                ->description($totals['lowestBalance'] < 0 ? 'O saldo fica negativo' : 'Nunca fica negativo')
                ->descriptionIcon($totals['lowestBalance'] < 0 ? Phosphor::WarningCircle : Phosphor::CheckCircle)
                ->color($totals['lowestBalance'] < 0 ? 'danger' : 'success')
                ->icon(Phosphor::TrendDown),
        ];
    }
}
