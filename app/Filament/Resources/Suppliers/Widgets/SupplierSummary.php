<?php

namespace App\Filament\Resources\Suppliers\Widgets;

use App\Enums\Language;
use App\Filament\Pages\InvoiceImportInbox;
use App\Models\Expense;
use App\Models\Supplier;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;
use MortalKiller\FilamentAppearance\Widgets\Concerns\GroupsStats;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

/**
 * The four figures under a supplier's name, as in the mock, in one card: what this year's
 * expenses add up to, what is still to pay, how many have no supporting document (a way into
 * "Importar faturas") and what you have spent with them since the start.
 */
class SupplierSummary extends StatsOverviewWidget
{
    use GroupsStats;
    use HasSkeletonPlaceholder;

    public ?Supplier $record = null;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int|array
    {
        return ['md' => 2, 'xl' => 4];
    }

    protected function getStats(): array
    {
        $supplier = $this->record;

        if (! $supplier instanceof Supplier) {
            return [];
        }

        $year = today()->year;
        $expenses = $supplier->expenses()->with('category')->orderBy('spent_on')->get();
        $total = fn ($rows): int => (int) $rows->sum(fn (Expense $expense): int => (int) $expense->total);

        $thisYear = $expenses->filter(fn (Expense $expense): bool => $expense->spent_on->year === $year);
        $allThisYear = (int) Expense::query()->whereYear('spent_on', $year)->get()->sum(fn (Expense $expense): int => (int) $expense->total);
        $unpaid = $expenses->reject(fn (Expense $expense): bool => (bool) $expense->is_paid);
        $oldestUnpaid = $unpaid->first();
        $withoutProof = $expenses->reject(fn (Expense $expense): bool => $expense->hasProof())->count();
        $first = $expenses->first();
        $count = fn (int $number): string => trans_choice('{0} No expenses|{1} 1 expense|[2,*] :count expenses', $number);
        $date = fn ($value): string => $value->format(Language::resolve(app()->getLocale())->dateFormat());

        return [
            Stat::make(__('Spent in :year', ['year' => $year]), Money::format($total($thisYear)))
                ->description($thisYear->isEmpty() || $allThisYear <= 0
                    ? $count($thisYear->count())
                    : __(':expenses · :percent of your expenses', [
                        'expenses' => $count($thisYear->count()),
                        'percent' => Number::percentage($total($thisYear) / $allThisYear * 100, locale: app()->getLocale()),
                    ])),
            Stat::make(__('To pay'), $unpaid->isEmpty() ? '—' : Money::format($total($unpaid)))
                ->description($oldestUnpaid === null
                    ? __('Nothing left to pay')
                    : __(':what · since :date', ['what' => $oldestUnpaid->description, 'date' => $date($oldestUnpaid->spent_on)]))
                ->valueColor($unpaid->isEmpty() ? null : 'warning'),
            Stat::make(__('Without supporting document'), (string) $withoutProof)
                ->description($withoutProof === 0 ? __('Every expense has one') : __('Import the invoice'))
                ->url($withoutProof > 0 && InvoiceImportInbox::canAccess() ? InvoiceImportInbox::getUrl() : null),
            Stat::make(__('Since the start'), Money::format($total($expenses)))
                ->description($first === null
                    ? $count(0)
                    : __(':expenses since :date', [
                        'expenses' => $count($expenses->count()),
                        'date' => $first->spent_on->locale(app()->getLocale())->translatedFormat('F Y'),
                    ])),
        ];
    }
}
