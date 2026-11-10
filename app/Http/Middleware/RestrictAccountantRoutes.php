<?php

namespace App\Http\Middleware;

use App\Filament\Pages\AccountantReview;
use App\Filament\Pages\AccountantReviewRecords;
use App\Filament\Pages\Dashboard;
use App\Support\AccountantAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RestrictAccountantRoutes
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! AccountantAccess::isReviewOnly()) {
            return $next($request);
        }

        if ($request->routeIs(Dashboard::getRouteName()) && $request === request()) {
            return redirect(AccountantReview::getUrl());
        }

        abort_unless($request->routeIs(
            AccountantReview::getRouteName(),
            AccountantReviewRecords::getRouteName(),
            'filament.admin.resources.expenses.download-proof',
        ), 403);

        return $next($request);
    }
}
