<?php

namespace App\Filament\Analysis\Widgets;

use App\Filament\Analysis\Concerns\ForecastsMonths;
use App\Support\Money;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Js;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

/**
 * Inflows vs outflows per month with the running balance as a neutral line, on one € axis
 * (no balance without a bank account for the activity).
 * Same validated colours as the dashboard cash flow chart.
 */
class CashForecastChart extends ChartWidget
{
    use ForecastsMonths;
    use HasSkeletonPlaceholder;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '300px';

    public function getHeading(): string
    {
        return $this->forecast()->showsBalance() ? __('Inflows, outflows and running balance') : __('Inflows and outflows');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $rows = $this->forecast()->months();

        return [
            'labels' => array_column($rows, 'label'),
            'datasets' => [
                ['label' => __('Inflows'), 'data' => Money::toEurosList(array_column($rows, 'inflows')), 'order' => 2],
                ['label' => __('Outflows'), 'data' => Money::toEurosList(array_column($rows, 'outflows')), 'order' => 2],
                ...($this->forecast()->showsBalance()
                    ? [['label' => __('Running balance'), 'data' => Money::toEurosList(array_column($rows, 'balance')), 'type' => 'line', 'order' => 1]]
                    : []),
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
                    grace: '5%',
                    ticks: {
                        maxTicksLimit: 5,
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
