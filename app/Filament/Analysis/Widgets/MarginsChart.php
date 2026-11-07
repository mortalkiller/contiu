<?php

namespace App\Filament\Analysis\Widgets;

use App\Filament\Analysis\Concerns\UsesMarginPeriod;
use App\Support\Money;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/**
 * Invoiced vs direct costs per business line (bars, the validated cash-flow pair) with the margin
 * as a neutral line, on one € axis.
 */
class MarginsChart extends ChartWidget
{
    use UsesMarginPeriod;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '300px';

    public function getHeading(): string
    {
        return 'Margem por linha de negócio';
    }

    public function getDescription(): string
    {
        return 'Faturado, custos diretos e margem em '.$this->period()->label().'.';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $rows = array_values($this->report()->byBusinessLine());

        return [
            'labels' => array_column($rows, 'label'),
            'datasets' => [
                ['label' => 'Faturado', 'data' => Money::toEurosList(array_column($rows, 'invoiced')), 'order' => 2],
                ['label' => 'Custos diretos', 'data' => Money::toEurosList(array_column($rows, 'directCosts')), 'order' => 2],
                ['label' => 'Margem', 'data' => Money::toEurosList(array_column($rows, 'margin')), 'type' => 'line', 'order' => 1],
            ],
        ];
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
        {
            interaction: { mode: 'index', intersect: false },
            datasets: {
                bar: {
                    backgroundColor: (ctx) => {
                        const dark = document.documentElement.classList.contains('dark')
                        return ctx.datasetIndex === 0 ? (dark ? '#2b7440' : '#15632f') : (dark ? '#b48c2b' : '#b97d4a')
                    },
                    borderWidth: 0,
                    borderRadius: 4,
                    borderSkipped: 'start',
                    categoryPercentage: 0.6,
                    barPercentage: 0.9,
                },
                line: {
                    borderColor: () => document.documentElement.classList.contains('dark') ? '#d4d4d4' : '#525252',
                    backgroundColor: () => document.documentElement.classList.contains('dark') ? '#d4d4d4' : '#525252',
                    borderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    showLine: false,
                },
            },
            scales: {
                x: { grid: { display: false } },
                y: {
                    border: { display: false },
                    grace: '5%',
                    ticks: {
                        maxTicksLimit: 5,
                        callback: (value) => new Intl.NumberFormat('pt-PT', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }).format(value),
                    },
                },
            },
            plugins: {
                legend: { display: true, position: 'top', align: 'end', labels: { usePointStyle: true, boxWidth: 8, boxHeight: 8 } },
                tooltip: {
                    callbacks: {
                        label: (ctx) => ' ' + ctx.dataset.label + ': ' + new Intl.NumberFormat('pt-PT', { style: 'currency', currency: 'EUR' }).format(ctx.parsed.y),
                    },
                },
            },
        }
        JS);
    }
}
