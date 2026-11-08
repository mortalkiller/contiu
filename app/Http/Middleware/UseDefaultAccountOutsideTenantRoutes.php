<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A few logged-in pages live outside /{account}/… (the user profile at /profile). They still use
 * the full panel layout, whose menu and "Novo" button link into an account, so they get the
 * user's own default account. Tenant routes are left alone: IdentifyTenant takes the account
 * from the URL and checks access.
 */
class UseDefaultAccountOutsideTenantRoutes
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::auth()->user();

        if ($user !== null && ! $request->route()?->hasParameter('tenant') && Filament::getTenant() === null) {
            $tenant = Filament::getUserDefaultTenant($user);

            if ($tenant !== null) {
                Filament::setTenant($tenant, isQuiet: true);
            }
        }

        return $next($request);
    }
}
