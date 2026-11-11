<?php

use NunoMaduro\Essentials\Configurables\AggressivePrefetching;
use NunoMaduro\Essentials\Configurables\AutomaticallyEagerLoadRelationships;
use NunoMaduro\Essentials\Configurables\FakeSleep;
use NunoMaduro\Essentials\Configurables\ForceScheme;
use NunoMaduro\Essentials\Configurables\ImmutableDates;
use NunoMaduro\Essentials\Configurables\PreventStrayRequests;
use NunoMaduro\Essentials\Configurables\ProhibitDestructiveCommands;
use NunoMaduro\Essentials\Configurables\SetDefaultPassword;
use NunoMaduro\Essentials\Configurables\ShouldBeStrict;
use NunoMaduro\Essentials\Configurables\Unguard;

return [
    AggressivePrefetching::class => false,
    AutomaticallyEagerLoadRelationships::class => true,
    FakeSleep::class => false,
    ForceScheme::class => true,
    ImmutableDates::class => false,
    PreventStrayRequests::class => false,
    ProhibitDestructiveCommands::class => true,
    SetDefaultPassword::class => true,
    ShouldBeStrict::class => env('APP_ENV') === 'local',
    Unguard::class => false,
];
