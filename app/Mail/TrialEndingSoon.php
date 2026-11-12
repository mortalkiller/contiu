<?php

namespace App\Mail;

use App\Enums\Language;
use App\Filament\Pages\AccountSubscription;
use App\Models\Account;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Three days before the free trial ends, to the account's owner: when the first charge is, how
 * much (VAT included), that it renews by itself and how to cancel before it. Promised on the
 * sign-up's payment step; sent by SendTrialEndingNotice from Stripe's trial_will_end webhook.
 */
class TrialEndingSoon extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Account $account,
        public CarbonImmutable $chargeOn,
        public string $planKey,
        public int $amountInCents,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Your free trial ends on :date', ['date' => $this->date()]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.trial-ending-soon', with: [
            'accountName' => $this->account->name,
            'date' => $this->date(),
            'amount' => Money::format($this->amountInCents),
            'plan' => mb_strtolower(__(AccountSubscription::PLANS[$this->planKey]['label'] ?? 'Monthly')),
            'renews' => $this->planKey === 'yearly' ? __('every year') : __('every month'),
            'url' => AccountSubscription::getUrl(panel: 'admin', tenant: $this->account),
        ]);
    }

    private function date(): string
    {
        return $this->chargeOn->format(Language::resolve(app()->getLocale())->dateFormat());
    }
}
