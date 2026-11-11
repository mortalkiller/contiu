<?php

use App\Http\Controllers\CalendarSubscriptionController;
use Illuminate\Support\Facades\Route;

Route::get('api/calendario/{token}.ics', CalendarSubscriptionController::class)
    ->where('token', '[a-f0-9]{64}')
    ->withoutMiddleware('web')
    ->middleware('throttle:60,1,calendar-subscriptions')
    ->name('calendar-subscriptions.show');
