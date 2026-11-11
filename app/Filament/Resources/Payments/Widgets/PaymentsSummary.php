<?php

namespace App\Filament\Resources\Payments\Widgets;

use App\Enums\ReconciliationStatus;
use App\Filament\Pages\BankStatementImport;
use App\Filament\Support\ProportionBar;
use App\Filament\Support\ReconciliationColumns;
use App\Models\Payment;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Collection;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * Above the "Recebimentos" list: what came in this month (with the last six months as a small
 * chart), this year's total, and how clients pay.
 */
class PaymentsSummary extends StatsOverviewWidget
{
    use HasSkeletonPlaceholder;

    private const array TONES = ['strong', 'medium', 'soft'];

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int|array
    {
        return ReconciliationColumns::enabled() ? ['md' => 2, 'xl' => 4] : ['md' => 3];
    }

    protected function getStats(): array
    {
        /** @var Collection<int, Payment> $recent */
        $recent = Payment::query()->whereDate('paid_on', '>=', today()->startOfMonth()->subMonths(5))->get();

        $byMonth = [];

        for ($monthsAgo = 5; $monthsAgo >= 0; $monthsAgo--) {
            $month = today()->startOfMonth()->subMonths($monthsAgo);

            $byMonth[] = (int) $recent->filter(fn (Payment $payment): bool => $payment->paid_on->isSameMonth($month))->sum('amount');
        }

        /** @var Collection<int, Payment> $thisYear */
        $thisYear = Payment::query()->whereYear('paid_on', today()->year)->get();

        $byMethod = $thisYear
            ->groupBy(fn (Payment $payment): string => $payment->method->getLabel())
            ->map(fn (Collection $payments): int => (int) $payments->sum('amount'))
            ->sortDesc();

        // The three most used methods; the rest goes together.
        $segments = [];

        foreach ($byMethod->take(3)->keys()->values() as $index => $label) {
            $segments[] = ['label' => $label, 'cents' => $byMethod[$label], 'tone' => self::TONES[$index]];
        }

        if ($byMethod->count() > 3) {
            $segments[] = ['label' => __('Others'), 'cents' => (int) $byMethod->skip(3)->sum(), 'tone' => 'clay'];
        }

        return [
            Stat::make(__('Received in :period', ['period' => today()->locale(app()->getLocale())->translatedFormat('F')]), Money::format((int) end($byMonth), locale: app()->getLocale()))
                ->description(__('Last 6 months'))
                ->chart($byMonth)
                ->color('primary')
                ->icon(Phosphor::HandCoins),
            Stat::make(__('Received in :period', ['period' => today()->year]), Money::format((int) $thisYear->sum('amount'), locale: app()->getLocale()))
                ->description(trans_choice('{0} No payments|{1} 1 payment|[2,*] :count payments', $thisYear->count()))
                ->icon(Phosphor::CalendarBlank),
            Stat::make(__('How clients pay you'), $byMethod->keys()->first() ?? '—')
                ->description($byMethod->isEmpty() ? __('No payments yet this year') : ProportionBar::render($segments))
                ->icon(Phosphor::Wallet),
            ...$this->reconciliationStat(),
        ];
    }

    /**
     * Payments the bank statement does not explain yet. Only with a bank account for the
     * activity: without one there is no statement to compare with.
     *
     * @return list<Stat>
     */
    private function reconciliationStat(): array
    {
        if (! ReconciliationColumns::enabled()) {
            return [];
        }

        $pending = count(ReconciliationColumns::reconciliation()->paymentIds(ReconciliationStatus::NotReconciled));

        return [
            Stat::make(__('Unreconciled with the bank'), (string) $pending)
                ->description($pending === 0 ? __('All matched to the bank statement') : __('Not yet on the bank statement'))
                ->color($pending === 0 ? 'success' : 'warning')
                ->url($pending === 0 ? null : BankStatementImport::getUrl())
                ->icon(Phosphor::Bank),
        ];
    }
}
