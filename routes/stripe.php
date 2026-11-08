<?php

use App\Http\Middleware\RequireStripeWebhookSecret;
use Illuminate\Support\Facades\Route;
use Laravel\Cashier\Http\Controllers\PaymentController;
use Laravel\Cashier\Http\Controllers\WebhookController;

/*
 * Cashier's own routes (Cashier::ignoreRoutes() in AppServiceProvider), registered here so the
 * webhook fails closed without a signing secret. Same paths and names as Cashier's; like
 * Cashier's, outside the "web" group (Stripe posts without a session or CSRF token).
 */
Route::prefix(config('cashier.path'))->name('cashier.')->group(function () {
    Route::get('payment/{id}', [PaymentController::class, 'show'])->name('payment');
    Route::post('webhook', [WebhookController::class, 'handleWebhook'])
        ->middleware(RequireStripeWebhookSecret::class)
        ->name('webhook');
});
