<?php

namespace App\Http\Middleware;

use App\Enums\Language;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The language picked in the footer of the sign-in pages (SetGuestLanguageController), for
 * whoever is not signed in yet. Once signed in, the user's own language wins (ApplyUserLanguage).
 */
class ApplyGuestLanguage
{
    public const string SESSION_KEY = 'guest_language';

    public function handle(Request $request, Closure $next): Response
    {
        $language = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        if ($request->user() === null && is_string($language) && Language::tryFrom($language) !== null) {
            app()->setLocale(Language::from($language)->locale());
        }

        return $next($request);
    }
}
