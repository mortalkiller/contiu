<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\InvoiceBenchmarkServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    InvoiceBenchmarkServiceProvider::class,
];
