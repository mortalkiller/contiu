<?php

use Illuminate\Support\Facades\Route;

// The Filament panel lives at the root ("/" is the dashboard, "/login" the sign-in page). The only
// public pages of its own are the legal texts linked from the sign-up (both in reserved_slugs).
Route::view('termos', 'legal.terms')->name('legal.terms');
Route::view('privacidade', 'legal.privacy')->name('legal.privacy');
