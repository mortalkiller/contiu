<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\CategoryDoughnut;
use App\Filament\Widgets\Concerns\UsesDashboardPeriod;
use Filament\Widgets\ChartWidget;

/**
 * Paid expenses per category in the selected period, as a doughnut.
 */
class ExpensesByCategoryChart extends ChartWidget
{
    use CategoryDoughnut;
    use UsesDashboardPeriod;

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '320px';

    public function getHeading(): string
    {
        return 'Despesas por categoria';
    }

    public function getDescription(): string
    {
        return 'Despesas pagas em '.$this->summary()->period->label().'.';
    }

    protected function amounts(): array
    {
        return $this->summary()->paidExpensesByCategory();
    }
}
