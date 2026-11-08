<?php

namespace App\Listeners;

use App\Mail\SubscriptionPaid;
use App\Models\Account;
use App\Models\SubscriptionPayment;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookReceived;

/**
 * Each paid subscription invoice (Stripe invoice.paid) is recorded and emailed to every super
 * admin: the legal invoice is issued outside the app and needs the account, NIF, address, amount
 * and period. Zero-amount invoices (the one opening a subscription during the trial) are skipped.
 *
 * The Stripe webhook endpoint must send invoice.paid: it is not among the events
 * `php artisan cashier:webhook` subscribes to by default.
 */
class RecordSubscriptionPayment
{
    public function handle(WebhookReceived $event): void
    {
        if (($event->payload['type'] ?? null) !== 'invoice.paid') {
            return;
        }

        $invoice = $event->payload['data']['object'];

        if ((int) ($invoice['amount_paid'] ?? 0) <= 0) {
            return;
        }

        $account = Cashier::findBillable($invoice['customer'] ?? null);

        if (! $account instanceof Account) {
            return;
        }

        // Stripe retries a webhook until it gets a 2xx: record and email once. A failed email
        // rolls the row back, so the retry sends it again.
        DB::transaction(function () use ($account, $invoice) {
            $payment = SubscriptionPayment::firstOrCreate(
                ['stripe_invoice_id' => $invoice['id']],
                [
                    'account_id' => $account->getKey(),
                    'amount' => (int) $invoice['amount_paid'],
                    'paid_at' => self::time(Arr::get($invoice, 'status_transitions.paid_at')) ?? now(),
                    // The subscription period is on the invoice line; the invoice's own period is
                    // the one before it (when usage would have been billed).
                    'period_start' => self::time(Arr::get($invoice, 'lines.data.0.period.start')),
                    'period_end' => self::time(Arr::get($invoice, 'lines.data.0.period.end')),
                    'billing_address' => $invoice['customer_address'] ?? null,
                ],
            );

            if (! $payment->wasRecentlyCreated) {
                return;
            }

            // whereHas, not Spatie's role() scope: that one throws while the role does not exist.
            User::query()
                ->whereHas('roles', fn ($roles) => $roles->where('name', config('filament-shield.super_admin.name', 'super_admin')))
                ->get()
                ->each(fn (User $admin) => Mail::to($admin)->send(new SubscriptionPaid($payment)));
        });
    }

    private static function time(mixed $timestamp): ?Carbon
    {
        return is_numeric($timestamp) ? Carbon::createFromTimestamp((int) $timestamp, config('app.timezone')) : null;
    }
}
