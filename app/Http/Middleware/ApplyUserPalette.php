<?php

namespace App\Http\Middleware;

use App\Support\Appearance\PaletteRegistry;
use Closure;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives Filament the colours of the palette the user chose (Perfil → Aparência). The panel
 * registers the Contiu colours when it boots, before the user is known, so this runs after
 * authentication and registers on top of them. The matching CSS variables for the navigation
 * bar and buttons come from the brand-head partial.
 */
class ApplyUserPalette
{
    public function handle(Request $request, Closure $next): Response
    {
        FilamentColor::register(PaletteRegistry::current()->filamentColors());

        return $next($request);
    }
}
