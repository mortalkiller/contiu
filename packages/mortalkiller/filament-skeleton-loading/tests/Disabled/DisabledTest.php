<?php

declare(strict_types=1);

use Filament\Support\Facades\FilamentAsset;
use MortalKiller\FilamentSkeletonLoading\SkeletonLoadingPlugin;
use MortalKiller\FilamentSkeletonLoading\Tests\Fixtures\RevenueChart;

it('falls back to plain Filament when switched off', function () {
    expect(FilamentAsset::getStyles([SkeletonLoadingPlugin::PACKAGE]))->toBeEmpty()
        ->and(view('filament::components.loading-section')->render())->not->toContain('fsl-skeleton')
        ->and(app(RevenueChart::class)->placeholder()->render())->not->toContain('fsl-skeleton');
});
