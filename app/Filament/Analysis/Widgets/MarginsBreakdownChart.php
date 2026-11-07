<?php

namespace App\Filament\Analysis\Widgets;

use App\Filament\Analysis\Concerns\UsesMarginPeriod;
use App\Filament\Widgets\Concerns\CategoryDoughnut;
use Filament\Widgets\ChartWidget;

/**
 * Part-to-whole of what was invoiced: direct costs, overhead (estrutura) and what is left.
 */
class MarginsBreakdownChart extends ChartWidget
{
    use CategoryDoughnut;
    use UsesMarginPeriod;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '220px';

    public function getHeading(): string
    {
        return 'Para onde vai o faturado';
    }

    public function getDescription(): string
    {
        return $this->period()->label().'.';
    }

    protected function amounts(): array
    {
        $totals = $this->report()->totals();

        return [
            'Custos diretos' => $totals['directCosts'],
            'Custos de estrutura' => $totals['overhead'],
            'Resultado' => $totals['result'],
        ];
    }
}
