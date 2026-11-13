<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\ToolsPanelProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    ToolsPanelProvider::class,
];
