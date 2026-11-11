<?php

namespace App\Filament\MonthClose\Widgets;

use App\Filament\MonthClose\Concerns\ReviewsMonth;
use App\Models\Expense;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

class MonthCloseStats extends StatsOverviewWidget
{
    use HasSkeletonPlaceholder;
    use ReviewsMonth;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int|array
    {
        return ['md' => 4];
    }

    /**
     * As in the mock: what was due this month, what came in (with how much of it), what was paid
     * and what is still unpaid, and the result in green or red.
     */
    protected function getStats(): array
    {
        $review = $this->review();
        $refunded = $review->refunded();
        $expected = $review->expected();
        $result = $review->result();
        $unpaid = (int) $review->expenses()->where('is_paid', false)->sum(fn (Expense $expense): int => (int) $expense->total);

        return [
            Stat::make(__('Expected receipts'), Money::format($expected, locale: app()->getLocale()))
                ->description(__('Due this month')),
            Stat::make(__('Received'), Money::format($review->received(), locale: app()->getLocale()))
                ->progress($expected > 0 ? min(100, round($review->received() / $expected * 100, 1)) : null),
            Stat::make(__('Expenses paid'), Money::format($review->paidExpenses(), locale: app()->getLocale()))
                ->description($unpaid > 0 ? __(':amount unpaid', ['amount' => Money::format($unpaid, locale: app()->getLocale())]) : __('All paid')),
            ...($refunded > 0 ? [Stat::make(__('Customer refunds'), Money::format($refunded, locale: app()->getLocale()))] : []),
            Stat::make(__('Monthly result'), Money::format($result, locale: app()->getLocale()))
                ->description(__('Received minus paid expenses'))
                ->valueColor(match (true) {
                    $result > 0 => 'success',
                    $result < 0 => 'danger',
                    default => null,
                }),
        ];
    }
}
