<?php

namespace App\Filament\Widgets\Concerns;

use App\Support\Money;
use App\Support\SliceFolder;
use Filament\Support\RawJs;

/**
 * Doughnut of amounts per category. Colours: the first six slots of the dataviz reference
 * categorical palette (light / dark steps), 2px surface gaps between slices; each legend entry
 * carries its amount and share, so identity never rests on colour alone.
 */
trait CategoryDoughnut
{
    /** @return array<string, float> category => amount */
    abstract protected function amounts(): array;

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $values = SliceFolder::fold(array_filter($this->amounts(), fn (int|float $value) => $value > 0));
        $total = array_sum($values);

        return [
            'labels' => array_map(
                fn (string $name, int|float $value) => $name.' · '.Money::format($value).' ('.($total > 0 ? round($value / $total * 100) : 0).'%)',
                array_keys($values),
                array_values($values),
            ),
            'datasets' => [
                ['label' => $this->getHeading(), 'data' => Money::toEurosList(array_values($values))],
            ],
        ];
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
        {
            cutout: '58%',
            scales: { x: { display: false }, y: { display: false } },
            datasets: {
                doughnut: {
                    backgroundColor: (ctx) => {
                        const dark = document.documentElement.classList.contains('dark')
                        const light = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300']
                        const night = ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#008300']
                        return (dark ? night : light)[ctx.dataIndex % 6]
                    },
                    borderColor: () => document.documentElement.classList.contains('dark') ? '#171717' : '#ffffff',
                    borderWidth: 2,
                    hoverOffset: 6,
                },
            },
            plugins: {
                legend: { display: true, position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, boxHeight: 8, padding: 12 } },
                tooltip: { callbacks: { label: (ctx) => ' ' + ctx.label } },
            },
        }
        JS);
    }
}
