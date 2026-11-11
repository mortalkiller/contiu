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
    AutomaticallyEagerLoadRelationships::class => false,
    FakeSleep::class => false,
    ForceScheme::class => false,
    ImmutableDates::class => false,
    PreventStrayRequests::class => false,
    ProhibitDestructiveCommands::class => false,
    SetDefaultPassword::class => false,
    ShouldBeStrict::class => env('APP_ENV') === 'local',
    Unguard::class => false,
];
