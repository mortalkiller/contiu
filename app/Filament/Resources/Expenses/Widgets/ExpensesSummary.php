<?php

namespace App\Filament\Resources\Expenses\Widgets;

use App\Enums\ExpenseKind;
use App\Filament\Support\ProportionBar;
use App\Models\Expense;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * Above the "Despesas" list: what is still to pay, what was paid this month (with the last six
 * months as a small chart), how many have no proof attached, and this year's split between
 * overhead ("Estrutura") and direct costs.
 */
class ExpensesSummary extends StatsOverviewWidget
{
    use HasSkeletonPlaceholder;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int|array
    {
        return ['md' => 2, 'xl' => 4];
    }

    protected function getStats(): array
    {
        $unpaid = Expense::query()->where('is_paid', false)->get();

        $paid = Expense::query()
            ->where('is_paid', true)
            ->whereDate('paid_on', '>=', today()->startOfMonth()->subMonths(5))
            ->get();

        $paidByMonth = [];

        for ($monthsAgo = 5; $monthsAgo >= 0; $monthsAgo--) {
            $month = today()->startOfMonth()->subMonths($monthsAgo);

            $paidByMonth[] = $paid
                ->filter(fn (Expense $expense): bool => $expense->paid_on?->isSameMonth($month) ?? false)
                ->sum(fn (Expense $expense): int => (int) $expense->total);
        }

        $thisYear = Expense::query()->whereYear('spent_on', today()->year)->get();
        $byKind = fn (ExpenseKind $kind): int => (int) $thisYear
            ->filter(fn (Expense $expense): bool => $expense->kind === $kind)
            ->sum(fn (Expense $expense): int => (int) $expense->total);

        $withoutProof = Expense::query()->whereNull('proof_path')->count();

        return [
            Stat::make('Por pagar', Money::format((int) $unpaid->sum(fn (Expense $expense): int => (int) $expense->total)))
                ->description($unpaid->isEmpty() ? 'Tudo pago' : trans_choice('{1} 1 despesa|[2,*] :count despesas', $unpaid->count()))
                ->color($unpaid->isEmpty() ? 'success' : 'warning')
                ->icon(Phosphor::Clock),
            Stat::make('Pagas em '.today()->translatedFormat('F'), Money::format((int) end($paidByMonth)))
                ->description('Últimos 6 meses')
                ->chart($paidByMonth)
                ->color('clay')
                ->icon(Phosphor::Receipt),
            Stat::make('Sem comprovativo', (string) $withoutProof)
                ->description($withoutProof === 0 ? 'Todas documentadas' : 'Junta-os em Importar faturas')
                ->color($withoutProof === 0 ? 'success' : null)
                ->icon(Phosphor::Paperclip),
            Stat::make('Em '.today()->year, Money::format($byKind(ExpenseKind::Overhead) + $byKind(ExpenseKind::Direct)))
                ->description(ProportionBar::render([
                    ['label' => 'Estrutura', 'cents' => $byKind(ExpenseKind::Overhead), 'tone' => 'clay'],
                    ['label' => 'Diretas', 'cents' => $byKind(ExpenseKind::Direct), 'tone' => 'strong'],
                ]))
                ->icon(Phosphor::ChartPieSlice),
        ];
    }
}
