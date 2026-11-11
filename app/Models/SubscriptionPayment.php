<?php

namespace App\Models;

use App\Enums\Language;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A paid subscription invoice (Stripe invoice.paid), recorded for the super admins to issue the
 * legal invoice outside the app. Belongs to an account but deliberately NOT scoped by it
 * (no BelongsToAccount): it is written by the webhook, with no current account, and only ever
 * read by super admins across accounts (AccountResource).
 */
class SubscriptionPayment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_at' => 'datetime',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'billing_address' => 'array',
            'invoiced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** "Rua das Flores, 12, 4050-262 Porto, PT", as Stripe Checkout collected it. */
    public function addressLine(): string
    {
        $address = $this->billing_address ?? [];

        return collect([
            $address['line1'] ?? null,
            $address['line2'] ?? null,
            trim(($address['postal_code'] ?? '').' '.($address['city'] ?? '')),
            $address['state'] ?? null,
            $address['country'] ?? null,
        ])->filter(fn (?string $part) => filled($part))->implode(', ');
    }

    /** "24/11/2026 a 24/12/2026": the subscription period the payment covers. */
    public function periodLabel(): string
    {
        if ($this->period_start === null || $this->period_end === null) {
            return '';
        }

        $dateFormat = Language::resolve(app()->getLocale())->dateFormat();

        return __(':start to :end', [
            'start' => $this->period_start->format($dateFormat),
            'end' => $this->period_end->format($dateFormat),
        ]);
    }
}
