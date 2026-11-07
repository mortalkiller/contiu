<?php

declare(strict_types=1);

namespace MortalKiller\FilamentSkeletonLoading\Concerns;

use Filament\Widgets\ChartWidget;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\TableWidget;
use Illuminate\Contracts\View\View;
use MortalKiller\FilamentSkeletonLoading\SkeletonLoadingPlugin;
use Throwable;

/**
 * For lazy widgets: while the widget loads, draw a skeleton shaped like it (stat tiles, a chart,
 * table rows) instead of an empty box. Override the `$skeleton…` properties to fine-tune.
 *
 * @property ?int $skeletonItems number of stat tiles (stats widgets)
 * @property ?int $skeletonRows number of rows (table widgets)
 */
trait HasSkeletonPlaceholder
{
    public function placeholder(): View
    {
        if (! SkeletonLoadingPlugin::isEnabled()) {
            return parent::placeholder();
        }

        return view('filament-skeleton-loading::placeholder', [
            ...$this->getPlaceholderData(),
            ...$this->getSkeletonData(),
        ]);
    }

    /**
     * @return array{kind: string, items: int, rows: int, chart: string, heading: ?string, height: ?string}
     */
    protected function getSkeletonData(): array
    {
        $kind = match (true) {
            $this instanceof StatsOverviewWidget => 'stats',
            $this instanceof ChartWidget => 'chart',
            $this instanceof TableWidget => 'table',
            default => 'generic',
        };

        return [
            'kind' => $kind,
            'items' => $this->skeletonItems ?? $this->skeletonStatColumns(),
            'rows' => $this->skeletonRows ?? 5,
            'chart' => $kind === 'chart' ? $this->skeletonChartShape() : 'bars',
            'heading' => $kind === 'chart' ? $this->skeletonHeading() : null,
            'height' => method_exists($this, 'getPlaceholderHeight') ? $this->getPlaceholderHeight() : null,
        ];
    }

    private function skeletonStatColumns(): int
    {
        if (! $this instanceof StatsOverviewWidget) {
            return 4;
        }

        $columns = $this->getColumns();

        return is_int($columns) ? max(1, $columns) : 4;
    }

    private function skeletonChartShape(): string
    {
        try {
            return match ($this->getType()) {
                'doughnut', 'pie', 'polarArea' => 'ring',
                'line', 'radar' => 'line',
                default => 'bars',
            };
        } catch (Throwable) {
            return 'bars';
        }
    }

    private function skeletonHeading(): ?string
    {
        try {
            $heading = $this->getHeading();

            return filled($heading) ? strip_tags((string) $heading) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
