<?php

namespace App\Filament\Resources\Expenses\Widgets;

use App\Enums\ExpenseKind;
use App\Filament\Pages\InvoiceImportInbox;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Support\ProportionBar;
use App\Models\Expense;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

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
        $monthLabels = [];

        for ($monthsAgo = 5; $monthsAgo >= 0; $monthsAgo--) {
            $month = today()->startOfMonth()->subMonths($monthsAgo);

            $paidByMonth[] = $paid
                ->filter(fn (Expense $expense): bool => $expense->paid_on?->isSameMonth($month) ?? false)
                ->sum(fn (Expense $expense): int => (int) $expense->total);
            $monthLabels[] = ucfirst($month->locale(app()->getLocale())->translatedFormat('F'));
        }

        $thisYear = Expense::query()->whereYear('spent_on', today()->year)->get();
        $byKind = fn (ExpenseKind $kind): int => (int) $thisYear
            ->filter(fn (Expense $expense): bool => $expense->kind === $kind)
            ->sum(fn (Expense $expense): int => (int) $expense->total);

        $withoutProof = Expense::query()->whereNull('proof_path')->count();

        return [
            Stat::make(__('Unpaid'), Money::format((int) $unpaid->sum(fn (Expense $expense): int => (int) $expense->total), locale: app()->getLocale()))
                ->description($unpaid->isEmpty() ? __('All paid') : __(':expenses · see only these', ['expenses' => trans_choice('{1} 1 expense|[2,*] :count expenses', $unpaid->count())]))
                ->valueColor($unpaid->isEmpty() ? null : 'warning')
                ->url($unpaid->isEmpty() ? null : ExpenseResource::getUrl('index', ['tab' => 'unpaid'])),
            Stat::make(__('Expenses paid in :month', ['month' => today()->locale(app()->getLocale())->translatedFormat('F')]), Money::format((int) end($paidByMonth), locale: app()->getLocale()))
                // As in the mock: one bar per month, the current one in full colour.
                ->bars($paidByMonth, 'clay', label: __('Last 6 months'), labels: $monthLabels, formatValueUsing: fn (int $cents): string => Money::format($cents, locale: app()->getLocale())),
            Stat::make(__('Missing supporting document'), (string) $withoutProof)
                ->description($withoutProof === 0 ? __('All documented') : __('Attach them in Import invoices'))
                ->url($withoutProof === 0 || ! InvoiceImportInbox::canAccess() ? null : InvoiceImportInbox::getUrl()),
            Stat::make(__('In :year', ['year' => today()->year]), Money::format($byKind(ExpenseKind::Overhead) + $byKind(ExpenseKind::Direct), locale: app()->getLocale()))
                ->description(ProportionBar::render([
                    ['label' => __('Overhead'), 'cents' => $byKind(ExpenseKind::Overhead), 'tone' => 'clay'],
                    ['label' => __('Direct expenses'), 'cents' => $byKind(ExpenseKind::Direct), 'tone' => 'strong'],
                ])),
        ];
    }
}
