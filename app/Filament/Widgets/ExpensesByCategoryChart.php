<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\CategoryDoughnut;
use App\Filament\Widgets\Concerns\UsesDashboardPeriod;
use MortalKiller\FilamentAppearance\Widgets\BreakdownChartWidget;

/**
 * Paid expenses per category in the selected period, as a doughnut.
 */
class ExpensesByCategoryChart extends BreakdownChartWidget
{
    use CategoryDoughnut;
    use UsesDashboardPeriod;

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    /** What went out takes the clay accent, as in the cash flow chart. */
    protected string $color = 'clay';

    public function getHeading(): string
    {
        return __('Expenses by category');
    }

    public function getDescription(): string
    {
        return __('Expenses paid in :period.', ['period' => $this->summary()->period->label()]);
    }

    protected function amounts(): array
    {
        return $this->summary()->paidExpensesByCategory();
    }
}
