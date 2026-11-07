<?php

declare(strict_types=1);

use MortalKiller\FilamentSkeletonLoading\Tests\Fixtures\ItemsRelationManager;

function packageCss(): string
{
    return (string) file_get_contents(__DIR__.'/../../resources/css/skeleton-loading.css');
}

it('draws a table skeleton for relation managers', function () {
    $html = app(ItemsRelationManager::class)->placeholder()->render();

    expect($html)->toContain('fsl-skeleton--table')
        ->and(substr_count($html, 'fsl-row'))->toBe(5);
});

it('draws notification placeholders in the notifications panel', function () {
    expect(packageCss())->toContain('.fi-no-notifications-loading-ctn::before');
});

it('draws form rows in lazy loaded sections', function () {
    expect(packageCss())->toContain('.fi-sc-loading::before');
});

it('keeps every loading container still for reduced motion', function () {
    $reducedMotion = substr(packageCss(), strpos(packageCss(), 'prefers-reduced-motion'));

    expect($reducedMotion)->toContain('.fi-no-notifications-loading-ctn::after')
        ->toContain('.fi-sc-loading::after');
});
