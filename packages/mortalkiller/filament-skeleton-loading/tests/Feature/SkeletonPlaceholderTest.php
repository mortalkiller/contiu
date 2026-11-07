<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentAsset;
use MortalKiller\FilamentSkeletonLoading\SkeletonLoadingPlugin;
use MortalKiller\FilamentSkeletonLoading\Tests\Fixtures\LatestOrders;
use MortalKiller\FilamentSkeletonLoading\Tests\Fixtures\Plain;
use MortalKiller\FilamentSkeletonLoading\Tests\Fixtures\RevenueChart;
use MortalKiller\FilamentSkeletonLoading\Tests\Fixtures\SalesStats;
use MortalKiller\FilamentSkeletonLoading\Tests\Fixtures\ShareChart;

function skeletonOf(string $widget): string
{
    return app($widget)->placeholder()->render();
}

it('registers its stylesheet', function () {
    expect(FilamentAsset::getStyles([SkeletonLoadingPlugin::PACKAGE]))->not->toBeEmpty()
        ->and(SkeletonLoadingPlugin::current())->toBeInstanceOf(SkeletonLoadingPlugin::class);
});

it('draws one tile per stats column', function () {
    expect(substr_count(skeletonOf(SalesStats::class), 'fsl-stat'))->toBe(3);
});

it('draws bars with the chart heading', function () {
    expect(skeletonOf(RevenueChart::class))->toContain('fsl-chart-bars')->toContain('Revenue');
});

it('draws a ring for doughnut charts', function () {
    expect(skeletonOf(ShareChart::class))->toContain('fsl-chart-ring');
});

it('draws the configured number of table rows', function () {
    expect(substr_count(skeletonOf(LatestOrders::class), 'fsl-row'))->toBe(3);
});

it('falls back to a generic skeleton', function () {
    expect(skeletonOf(Plain::class))->toContain('fsl-skeleton--generic');
});

it('replaces the lazy loading section of Filament', function () {
    expect(view('filament::components.loading-section')->render())
        ->toContain('fsl-skeleton--generic')
        ->toContain('role="status"');
});

it('can turn the shimmer off', function () {
    $plugin = SkeletonLoadingPlugin::make()->shimmer(false);

    expect($plugin->hasShimmer())->toBeFalse()
        ->and(Filament::getCurrentPanel()->getId())->toBe('test');
});
