<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplyUserLanguage
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $next($request);
        }

        $previous = app()->getLocale();
        app()->setLocale($user->preferredLocale());

        try {
            return $next($request);
        } finally {
            app()->setLocale($previous);
        }
    }
}
