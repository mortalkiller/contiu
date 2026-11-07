<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\CategoryDoughnut;
use App\Filament\Widgets\Concerns\UsesDashboardPeriod;
use Filament\Widgets\ChartWidget;

/**
 * What came in per income category (of the project or retainer) in the selected period.
 */
class IncomeByCategoryChart extends ChartWidget
{
    use CategoryDoughnut;
    use UsesDashboardPeriod;

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '320px';

    public function getHeading(): string
    {
        return 'Receitas por categoria';
    }

    public function getDescription(): string
    {
        return 'Recebido em '.$this->summary()->period->label().'.';
    }

    protected function amounts(): array
    {
        return $this->summary()->receivedByCategory();
    }
}
