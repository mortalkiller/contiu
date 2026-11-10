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
            $segments[] = ['label' => 'Outros', 'cents' => (int) $byMethod->skip(3)->sum(), 'tone' => 'clay'];
        }

        return [
            Stat::make('Recebido em '.today()->translatedFormat('F'), Money::format((int) end($byMonth)))
                ->description('Últimos 6 meses')
                ->chart($byMonth)
                ->color('primary')
                ->icon(Phosphor::HandCoins),
            Stat::make('Recebido em '.today()->year, Money::format((int) $thisYear->sum('amount')))
                ->description(trans_choice('{0} Nenhum recebimento|{1} 1 recebimento|[2,*] :count recebimentos', $thisYear->count()))
                ->icon(Phosphor::CalendarBlank),
            Stat::make('Como te pagam', $byMethod->keys()->first() ?? '—')
                ->description($byMethod->isEmpty() ? 'Ainda sem recebimentos este ano' : ProportionBar::render($segments))
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
            Stat::make('Por reconciliar com o banco', (string) $pending)
                ->description($pending === 0 ? 'Tudo explicado pelo extrato' : 'Ainda não aparecem no extrato')
                ->color($pending === 0 ? 'success' : 'warning')
                ->url($pending === 0 ? null : BankStatementImport::getUrl())
                ->icon(Phosphor::Bank),
        ];
    }
}
