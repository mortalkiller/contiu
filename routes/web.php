<?php

use App\Http\Controllers\BlogController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\SetGuestLanguageController;
use App\Support\PanelLocation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * The public site (resources/views/site) at the root of the APP_URL host. The Filament panel is at
 * /app next to it, or on its own domain in production (App\Support\PanelLocation).
 */
Route::domain(PanelLocation::siteDomain())->name('site.')->group(function (): void {
    Route::view('/', 'site.home')->name('home');
    Route::view('funcionalidades', 'site.features')->name('features');
    Route::view('precos', 'site.pricing')->name('pricing');
    Route::view('contabilista', 'site.accountant')->name('accountant');
    Route::get('blogue', [BlogController::class, 'index'])->name('blog');
    Route::get('blogue/{slug}', [BlogController::class, 'show'])->name('blog.post');
    Route::get('ajuda', [HelpController::class, 'index'])->name('help');
    Route::get('ajuda/pesquisa', [HelpController::class, 'search'])->name('help.search');
    Route::get('ajuda/{theme}', [HelpController::class, 'theme'])->name('help.theme');
    Route::get('ajuda/{theme}/{article}', [HelpController::class, 'article'])->name('help.article');
    Route::get('sitemap.xml', fn () => response()->view('site.sitemap')->header('Content-Type', 'application/xml'))->name('sitemap');
});

// For search engines: the site and its sitemap, never the panel (nor any of its own domain).
Route::get('robots.txt', fn (Request $request) => response()->view('site.robots', [
    'onPanelDomain' => PanelLocation::domain() !== null && $request->getHost() === PanelLocation::domain(),
])->header('Content-Type', 'text/plain; charset=UTF-8'))->name('robots');

// The legal texts, linked from the site's footer and from the sign-up.
Route::view('termos', 'legal.terms')->name('legal.terms');
Route::view('privacidade', 'legal.privacy')->name('legal.privacy');

Route::domain(PanelLocation::domain())->prefix(PanelLocation::path())->group(function (): void {
    // The language of the sign-in pages, before anyone signs in (their footer).
    Route::get('idioma/{language}', SetGuestLanguageController::class)->name('guest-language');

    // With public sign-up closed, Filament has no /register: a 404, not the panel's
    // "/{account}" route sending the visitor to sign in.
    if (! config('contiu.registration_open')) {
        Route::get('register', fn () => abort(404));
    }
});

require __DIR__.'/calendar-subscriptions.php';
