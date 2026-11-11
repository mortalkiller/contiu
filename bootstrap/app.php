<?php

use App\Http\Middleware\ApplyUserLanguage;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Stripe's webhook (Cashier), outside the "web" middleware group.
        then: fn () => Route::group([], base_path('routes/stripe.php')),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [ApplyUserLanguage::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
