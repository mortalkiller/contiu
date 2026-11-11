<?php

namespace App\Filament\Resources\Suppliers\Widgets;

use App\Models\Expense;
use Filament\Widgets\Widget;
use Illuminate\Support\Number;
use MortalKiller\FilamentAppearance\Support\BarList;

/**
 * "Onde gastas mais", beside the "Fornecedores" list as in the mock: each supplier's share of
 * this year's expenses, the biggest first, in the colour of costs.
 */
class SuppliersBySpend extends Widget
{
    private const int SHOWN = 6;

    protected string $view = 'filament.widgets.bar-list';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array{heading: string, list: BarList, emptyLabel: string}
     */
    protected function getViewData(): array
    {
        $bySupplier = Expense::query()
            ->whereYear('spent_on', today()->year)
            ->with('supplier')
            ->get()
            ->groupBy(fn (Expense $expense): string => $expense->supplier->name ?? __('No supplier'))
            ->map(fn ($group): int => (int) $group->sum(fn (Expense $expense): int => (int) $expense->total))
            ->filter()
            ->sortDesc();

        $total = max(1, (int) $bySupplier->sum());
        $shares = $bySupplier->take(self::SHOWN);

        if ($bySupplier->count() > self::SHOWN) {
            $shares->put(__('Others'), (int) $bySupplier->skip(self::SHOWN)->sum());
        }

        $list = BarList::make()->color('clay');

        foreach ($shares as $name => $cents) {
            $list->item((string) $name, Number::percentage($cents / $total * 100, locale: app()->getLocale()), $cents);
        }

        return [
            'heading' => __('Where you spend the most'),
            'list' => $list,
            'emptyLabel' => __('No expenses yet this year'),
        ];
    }
}
