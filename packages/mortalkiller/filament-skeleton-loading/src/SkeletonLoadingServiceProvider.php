<?php

declare(strict_types=1);

namespace MortalKiller\FilamentSkeletonLoading;

use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\Facades\View;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class SkeletonLoadingServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('filament-skeleton-loading')
            ->hasConfigFile()
            ->hasTranslations()
            ->hasViews();
    }

    public function packageBooted(): void
    {
        if (! SkeletonLoadingPlugin::isEnabled()) {
            return;
        }

        // Filament's lazy widgets and sections render `filament::components.loading-section`, a grey
        // pulsing box. Putting this package's copy first makes every one of them a skeleton.
        View::prependNamespace('filament', __DIR__.'/../resources/views/overrides');

        FilamentAsset::register([
            Css::make('skeleton-loading', __DIR__.'/../resources/css/skeleton-loading.css'),
        ], package: SkeletonLoadingPlugin::PACKAGE);
    }
}
