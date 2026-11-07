<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesDashboardPeriod;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/**
 * Monthly received vs paid expenses (grouped bars) with the month's result as a neutral line.
 * One € axis for all three series. Colours are chosen per theme at draw time; the pairs were
 * checked with the dataviz palette validator (light #15632f/#b97d4a, dark #2b7440/#b48c2b).
 */
class CashFlowChart extends ChartWidget
{
    use UsesDashboardPeriod;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '300px';

    public function getHeading(): string
    {
        return $this->period()->isMonth() ? 'Cash flow diário' : 'Cash flow mensal';
    }

    public function getDescription(): string
    {
        return 'Recebido e despesas pagas em '.$this->summary()->period->label().', com o resultado de cada '.($this->period()->isMonth() ? 'dia' : 'mês').'.';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $flow = $this->summary()->cashFlow();

        return [
            'labels' => array_column($flow, 'label'),
            'datasets' => [
                ['label' => 'Recebido', 'data' => array_column($flow, 'received'), 'order' => 2],
                ['label' => 'Despesas pagas', 'data' => array_column($flow, 'paid'), 'order' => 2],
                ['label' => 'Resultado', 'data' => array_column($flow, 'result'), 'type' => 'line', 'order' => 1],
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
                    categoryPercentage: 0.7,
                    barPercentage: 0.9,
                },
                line: {
                    borderColor: () => document.documentElement.classList.contains('dark') ? '#d4d4d4' : '#525252',
                    backgroundColor: () => document.documentElement.classList.contains('dark') ? '#d4d4d4' : '#525252',
                    borderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    tension: 0,
                },
            },
            scales: {
                x: { grid: { display: false } },
                y: {
                    border: { display: false },
                    // Nice positive steps (~4 of them) from 0, and only as much room below zero as a
                    // negative month needs (a quarter step at a time), instead of a whole extra step.
                    afterDataLimits: (scale) => {
                        const values = scale.chart.data.datasets.flatMap((dataset) => dataset.data.map(Number))
                        const low = Math.min(0, ...values)
                        const high = Math.max(0, ...values)
                        const raw = (high || 1) / 4
                        const magnitude = Math.pow(10, Math.floor(Math.log10(raw)))
                        const step = [1, 2, 2.5, 5, 10].map((m) => m * magnitude).find((s) => s >= raw)
                        scale._pressiuStep = step
                        scale.max = Math.max(step, Math.ceil(high / step) * step)
                        scale.min = low < 0 ? -Math.ceil(-low / (step / 4)) * (step / 4) : 0
                    },
                    afterBuildTicks: (scale) => {
                        const step = scale._pressiuStep
                        if (! step) return
                        const ticks = scale.min < 0 ? [{ value: scale.min }] : []
                        for (let value = 0; value <= scale.max + step / 1000; value += step) ticks.push({ value })
                        scale.ticks = ticks
                    },
                    ticks: {
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
