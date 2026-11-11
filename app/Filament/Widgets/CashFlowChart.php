<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesDashboardPeriod;
use App\Support\Money;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Js;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

/**
 * Monthly received vs paid expenses (grouped bars) with the month's result as a neutral line.
 * One € axis for all three series. Colours are read at draw time: what came in takes the brand
 * colour of the user's palette (--fap-primary, mortalkiller/filament-appearance), what went out
 * the clay accent, and the result stays neutral.
 */
class CashFlowChart extends ChartWidget
{
    use HasSkeletonPlaceholder;
    use UsesDashboardPeriod;

    protected static ?int $sort = 2;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '300px';

    public function getHeading(): string
    {
        return $this->period()->isMonth() ? __('Daily cash flow') : __('Monthly cash flow');
    }

    public function getDescription(): string
    {
        return __('Payments received and expenses paid in :period, with the result for each :unit.', ['period' => $this->summary()->period->label(), 'unit' => $this->period()->isMonth() ? __('day') : __('month')]);
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
                ['label' => __('Received'), 'data' => Money::toEurosList(array_column($flow, 'received')), 'order' => 2],
                ['label' => __('Expenses paid'), 'data' => Money::toEurosList(array_column($flow, 'paid')), 'order' => 2],
                ...($this->summary()->refunded() > 0 ? [
                    ['label' => __('Customer refunds'), 'data' => Money::toEurosList(array_column($flow, 'refunded')), 'order' => 2, 'backgroundColor' => '#986174'],
                ] : []),
                ['label' => __('Result'), 'data' => Money::toEurosList(array_column($flow, 'result')), 'type' => 'line', 'order' => 1],
            ],
        ];
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(str_replace('__CONTIU_LOCALE__', Js::from(str_replace('_', '-', app()->getLocale()))->toHtml(), <<<'JS'
        {
            interaction: { mode: 'index', intersect: false },
            datasets: {
                bar: {
                    backgroundColor: (ctx) => {
                        const root = getComputedStyle(document.documentElement)
                        const dark = document.documentElement.classList.contains('dark')
                        return ctx.datasetIndex === 0
                            ? (root.getPropertyValue('--fap-primary').trim() || '#15632f')
                            : (root.getPropertyValue(dark ? '--clay-400' : '--clay-500').trim() || '#b97d4a')
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
                        callback: (value) => new Intl.NumberFormat(__CONTIU_LOCALE__, { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }).format(value),
                    },
                },
            },
            plugins: {
                legend: { display: true, position: 'top', align: 'end', labels: { usePointStyle: true, boxWidth: 8, boxHeight: 8 } },
                tooltip: {
                    callbacks: {
                        label: (ctx) => ' ' + ctx.dataset.label + ': ' + new Intl.NumberFormat(__CONTIU_LOCALE__, { style: 'currency', currency: 'EUR' }).format(ctx.parsed.y),
                    },
                },
            },
        }
        JS));
    }
}
