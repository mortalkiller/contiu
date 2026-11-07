<?php

declare(strict_types=1);

namespace MortalKiller\FilamentSkeletonLoading\Tests;

abstract class DisabledTestCase extends TestCase
{
    public function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('filament-skeleton-loading.enabled', false);
    }
}
