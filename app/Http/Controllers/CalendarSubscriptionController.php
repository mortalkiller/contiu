<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Support\CalendarSubscriptionFeed;
use App\Support\CalendarSubscriptions;
use App\Support\CurrentAccount;
use Illuminate\Http\Response;

final class CalendarSubscriptionController extends Controller
{
    public function __invoke(string $token, CalendarSubscriptions $subscriptions, CalendarSubscriptionFeed $feed): Response
    {
        $headers = [
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
        ];

        $subscription = $subscriptions->findByToken($token);
        $account = $subscription === null ? null : Account::query()->find($subscription['accountId']);

        if ($account === null) {
            return response('', 404, $headers);
        }

        $locale = app()->getLocale();
        app()->setLocale($account->owner()?->preferredLocale() ?? config('app.locale'));

        try {
            $calendar = CurrentAccount::run($account, fn (): string => $feed->render($subscription['includeReceivables']));

            return response($calendar, 200, [
                ...$headers,
                'Content-Type' => 'text/calendar; charset=UTF-8',
                'Content-Disposition' => 'inline; filename="contiu.ics"',
            ]);
        } finally {
            app()->setLocale($locale);
        }
    }
}
