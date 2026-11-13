<?php

namespace App\Filament\Support;

use Filament\Panel;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use MortalKiller\FilamentAppearance\AppearancePlugin;
use MortalKiller\FilamentPageHeader\PageHeaderPlugin;
use MortalKiller\FilamentSkeletonLoading\SkeletonLoadingPlugin;

final class ContiuPanelLayout
{
    public static function apply(Panel $panel): Panel
    {
        return $panel
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->brandLogoHeight(fn (): string => AppearancePlugin::isOnSimplePage() ? '2.5rem' : '2rem')
            ->colors([
                'primary' => BrandPalette::primary(),
                'gray' => BrandPalette::gray(),
                'clay' => BrandPalette::clay(),
                'success' => Color::Emerald,
                'warning' => Color::Amber,
                'danger' => Color::Red,
                'info' => Color::Sky,
            ])
            ->font('Geist')
            ->monoFont('Geist Mono')
            ->sidebarWidth('15.5rem')
            ->sidebarCollapsibleOnDesktop()
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => view('filament.partials.brand-head')->render())
            ->plugins([
                SkeletonLoadingPlugin::make()
                    ->pageLoader()
                    ->pageLoaderSymbol(fn () => view('filament.partials.contiu-symbol'))
                    ->pageLoaderLabel(fn (): string => __('Loading…')),
                PageHeaderPlugin::make(),
            ]);
    }

    public static function appearance(): AppearancePlugin
    {
        return AppearancePlugin::make()
            ->palettes(fn (): array => ContiuPalettes::all())
            ->defaultPalette(ContiuPalettes::DEFAULT)
            ->customPalettes()
            ->brandLogo(
                fn (): string => asset('brand/contiu/contiu-logo-black.svg'),
                dark: fn (): string => asset('brand/contiu/contiu-logo-white.svg'),
            )
            ->collapsedBrandLogo(
                fn (): string => asset('brand/contiu/contiu-symbol-black.svg'),
                dark: fn (): string => asset('brand/contiu/contiu-symbol-white.svg'),
            )
            ->tableSearchAtStart()
            ->underlinedTabs()
            ->mobileTables()
            ->mobileEdgeToEdge();
    }
}
