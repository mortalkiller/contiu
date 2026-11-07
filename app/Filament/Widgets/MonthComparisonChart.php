<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesDashboardPeriod;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/**
 * Shown instead of the monthly cash flow chart when a month is chosen: the month next to the
 * month before, for what came in, what was paid and the result. One € axis.
 * Colours validated with the dataviz palette script: light #15632f / #3991b7, dark #2b7440 / #479dc4.
 */
class MonthComparisonChart extends ChartWidget
{
    use UsesDashboardPeriod;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '300px';

    public function getHeading(): string
    {
        return 'Comparação com o mês anterior';
    }

    public function getDescription(): string
    {
        return $this->period()->label().' vs '.$this->period()->previous()->label();
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $current = $this->summary();
        $previous = $current->previous();
        $figures = fn ($summary) => [$summary->received(), $summary->paidExpenses(), $summary->cashResult()];

        return [
            'labels' => ['Recebido', 'Despesas pagas', 'Resultado'],
            'datasets' => [
                ['label' => $previous->period->label(), 'data' => $figures($previous)],
                ['label' => $current->period->label(), 'data' => $figures($current)],
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
                        return ctx.datasetIndex === 1 ? (dark ? '#2b7440' : '#15632f') : (dark ? '#479dc4' : '#3991b7')
                    },
                    borderWidth: 0,
                    borderRadius: 4,
                    borderSkipped: 'start',
                    categoryPercentage: 0.6,
                    barPercentage: 0.9,
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
