<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fails the Stripe webhook closed. Cashier only checks the signature when a secret is set, so
 * without one anybody could post a customer.subscription.* event and give an account access.
 * Outside local and testing, no secret means no webhook. With a secret, the signature check is
 * Cashier's own (VerifyWebhookSignature).
 */
final class RequireStripeWebhookSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        if (blank(config('cashier.webhook.secret')) && ! app()->environment(['local', 'testing'])) {
            Log::error('Stripe webhook refused: STRIPE_WEBHOOK_SECRET is not set.');

            abort(403);
        }

        return $next($request);
    }
}
