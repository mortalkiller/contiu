<?php

namespace App\Mail;

use App\Filament\Resources\Accounts\AccountResource;
use App\Models\SubscriptionPayment;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * To the super admins only (never the account): a subscription payment came in, with what the
 * legal invoice needs. Sent by RecordSubscriptionPayment, queued and only once the payment row is
 * committed, so a failing mail never undoes the row.
 */
class SubscriptionPaid extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public SubscriptionPayment $payment)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Pagamento recebido: {$this->payment->account->name} (".Money::format($this->payment->amount).')',
        );
    }

    public function content(): Content
    {
        $account = $this->payment->account;

        return new Content(markdown: 'mail.subscription-paid', with: [
            'account' => $account,
            'address' => $this->payment->addressLine(),
            'amount' => Money::format($this->payment->amount),
            'paidOn' => $this->payment->paid_at->format('d/m/Y'),
            'period' => $this->payment->periodLabel(),
            'url' => AccountResource::getUrl('view', ['record' => $account], panel: 'admin', tenant: $account),
        ]);
    }
}
