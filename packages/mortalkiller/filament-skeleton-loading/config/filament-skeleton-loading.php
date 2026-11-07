<?php

declare(strict_types=1);

return [

    /*
     * Turn the skeletons on or off for the whole app. Off: Filament's own grey placeholder and
     * spinner come back, and the trait falls back to them.
     */
    'enabled' => (bool) env('SKELETON_LOADING_ENABLED', true),

    /*
     * Defer every table of every panel that uses the plugin (Filament's `deferLoading()`), so they
     * all show skeleton rows while loading. The plugin's `deferTables()` overrides this per panel.
     */
    'defer_tables' => (bool) env('SKELETON_LOADING_DEFER_TABLES', false),

];
