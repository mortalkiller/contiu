<?php

declare(strict_types=1);

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\HtmlString;
use MortalKiller\FilamentSkeletonLoading\SkeletonLoadingPlugin;

it('is off by default', function () {
    expect(SkeletonLoadingPlugin::make()->hasPageLoader())->toBeFalse()
        ->and(SkeletonLoadingPlugin::make()->pageLoader()->hasPageLoader())->toBeTrue()
        ->and(SkeletonLoadingPlugin::make()->pageLoader()->pageLoader(false)->hasPageLoader())->toBeFalse();
});

it('renders an accessible full-screen loader with a generic symbol', function () {
    $html = SkeletonLoadingPlugin::make()->pageLoader()->renderPageLoader();

    expect($html)->toContain('id="fsl-page-loader"')
        ->toContain('role="status"')
        ->toContain('fsl-loader-piece')
        ->toContain('--fsl-loader-delay: 300ms');
});

it('takes the brand symbol, delay and label from the app', function () {
    $html = SkeletonLoadingPlugin::make()
        ->pageLoader()
        ->pageLoaderSymbol(fn () => new HtmlString('<svg class="brand"><path class="fsl-loader-piece"/></svg>'))
        ->pageLoaderDelay(500)
        ->pageLoaderLabel('A carregar…')
        ->renderPageLoader();

    expect($html)->toContain('<svg class="brand">')
        ->toContain('--fsl-loader-delay: 500ms')
        ->toContain('A carregar…');
});

it('styles the loader in its stylesheet, still for reduced motion', function () {
    $css = (string) file_get_contents(__DIR__.'/../../resources/css/skeleton-loading.css');

    expect($css)->toContain('#fsl-page-loader')->toContain('.fsl-loader-piece');
});

it('renders a view symbol as markup instead of escaping it', function () {
    $symbol = new class implements Renderable
    {
        public function render(): string
        {
            return '<svg class="brand"><path class="fsl-loader-piece"/></svg>';
        }
    };

    $html = SkeletonLoadingPlugin::make()->pageLoader()->pageLoaderSymbol(fn () => $symbol)->renderPageLoader();

    expect($html)->toContain('<path class="fsl-loader-piece"/>')
        ->not->toContain('&lt;svg');
});

it('falls back to three dots when the app gives no symbol', function () {
    $html = SkeletonLoadingPlugin::make()->pageLoader()->renderPageLoader();

    expect(substr_count($html, '<circle class="fsl-loader-piece"'))->toBe(3)
        ->and($html)->toContain('fsl-loader-symbol--generic');
});

it('says loading in the app language when no label is given', function (string $locale, string $label) {
    app()->setLocale($locale);

    expect(SkeletonLoadingPlugin::make()->pageLoader()->renderPageLoader())->toContain($label);
})->with([
    ['en', 'Loading…'],
    ['pt', 'A carregar…'],
    ['pt_PT', 'A carregar…'],
    ['pt_BR', 'Carregando…'],
]);
