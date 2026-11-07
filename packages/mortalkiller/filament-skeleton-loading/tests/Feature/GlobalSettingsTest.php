<?php

declare(strict_types=1);

use Filament\Tables\Table;
use MortalKiller\FilamentSkeletonLoading\SkeletonLoadingPlugin;
use MortalKiller\FilamentSkeletonLoading\Tests\Fixtures\LatestOrders;

it('ships a config file with skeletons on and table deferral off', function () {
    expect(config('filament-skeleton-loading.enabled'))->toBeTrue()
        ->and(config('filament-skeleton-loading.defer_tables'))->toBeFalse();
});

it('leaves tables alone by default', function () {
    expect(Table::make(app(LatestOrders::class))->isLoadingDeferred())->toBeFalse();
});

it('defers every table when the defer_tables setting is on', function () {
    config(['filament-skeleton-loading.defer_tables' => true]);

    expect(Table::make(app(LatestOrders::class))->isLoadingDeferred())->toBeTrue();
});

it('lets the plugin decide per table over the setting', function () {
    config(['filament-skeleton-loading.defer_tables' => true]);
    SkeletonLoadingPlugin::current()->deferTables(fn (Table $table): bool => false);

    expect(Table::make(app(LatestOrders::class))->isLoadingDeferred())->toBeFalse();
});
