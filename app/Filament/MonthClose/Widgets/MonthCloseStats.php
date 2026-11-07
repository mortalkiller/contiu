<?php

namespace App\Filament\MonthClose\Widgets;

use App\Filament\MonthClose\Concerns\ReviewsMonth;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class MonthCloseStats extends StatsOverviewWidget
{
    use HasSkeletonPlaceholder;
    use ReviewsMonth;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int|array
    {
        return ['md' => 4];
    }

    protected function getStats(): array
    {
        $review = $this->review();

        return [
            Stat::make('Previsto receber', Money::format($review->expected()))
                ->description('Vencimentos deste mês')
                ->icon(Phosphor::CalendarCheck),
            Stat::make('Recebido', Money::format($review->received()))
                ->icon(Phosphor::HandCoins),
            Stat::make('Despesas pagas', Money::format($review->paidExpenses()))
                ->icon(Phosphor::Receipt),
            Stat::make('Resultado do mês', Money::format($review->result()))
                ->color($review->result() < 0 ? 'danger' : null)
                ->icon(Phosphor::Scales),
        ];
    }
}
