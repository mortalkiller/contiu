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
        $refunded = $review->refunded();

        return [
            Stat::make(__('Expected receipts'), Money::format($review->expected(), locale: app()->getLocale()))
                ->description(__('Due this month'))
                ->icon(Phosphor::CalendarCheck),
            Stat::make(__('Received'), Money::format($review->received(), locale: app()->getLocale()))
                ->icon(Phosphor::HandCoins),
            Stat::make(__('Expenses paid'), Money::format($review->paidExpenses(), locale: app()->getLocale()))
                ->icon(Phosphor::Receipt),
            ...($refunded > 0 ? [Stat::make(__('Customer refunds'), Money::format($refunded, locale: app()->getLocale()))] : []),
            Stat::make(__('Monthly result'), Money::format($review->result(), locale: app()->getLocale()))
                ->color($review->result() < 0 ? 'danger' : null)
                ->icon(Phosphor::Scales),
        ];
    }
}
