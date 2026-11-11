<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\CategoryDoughnut;
use App\Filament\Widgets\Concerns\UsesDashboardPeriod;
use MortalKiller\FilamentAppearance\Widgets\BreakdownChartWidget;

/**
 * What came in per income category (of the project or retainer) in the selected period.
 */
class IncomeByCategoryChart extends BreakdownChartWidget
{
    use CategoryDoughnut;
    use UsesDashboardPeriod;

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    /** What came in takes the palette colour. */
    protected string $color = 'primary';

    public function getHeading(): string
    {
        return __('Income by category');
    }

    public function getDescription(): string
    {
        return __('Received in :period.', ['period' => $this->summary()->period->label()]);
    }

    protected function amounts(): array
    {
        return $this->summary()->receivedByCategory();
    }
}
