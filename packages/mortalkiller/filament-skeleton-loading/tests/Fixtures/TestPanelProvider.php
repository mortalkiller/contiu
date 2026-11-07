<?php

declare(strict_types=1);

namespace MortalKiller\FilamentSkeletonLoading\Tests\Fixtures;

use Filament\Panel;
use Filament\PanelProvider;
use MortalKiller\FilamentSkeletonLoading\SkeletonLoadingPlugin;

final class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('test')
            ->path('test')
            ->default()
            ->plugin(SkeletonLoadingPlugin::make());
    }
}
