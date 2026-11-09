<?php

namespace App\Mail;

use App\Mail\Concerns\SendsAsAccount;
use App\Support\Money;
use App\Support\TaxObligation;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * On the 1st of the month: what the account has to pay or deliver (overdue ones flagged). The
 * account name, reply-to and rows are resolved when built, as the queue worker has no current account.
 */
class TaxObligationsDigest extends Mailable implements ShouldQueue
{
    use Queueable, SendsAsAccount, SerializesModels;

    /** @var list<array{title: string, amount: ?string, due_on: ?string, overdue: bool}> */
    public array $items;

    public string $account;

    public string $url;

    /** @param  list<TaxObligation>  $obligations */
    public function __construct(array $obligations, string $url)
    {
        $this->items = array_map(fn (TaxObligation $obligation) => [
            'title' => $obligation->title,
            'amount' => $obligation->amount === null ? null : Money::format($obligation->amount),
            'due_on' => $obligation->dueOn?->format('d/m/Y'),
            'overdue' => $obligation->status === 'overdue',
        ], $obligations);
        $this->account = $this->accountName();
        foreach ($this->replyToOwner() as $address) {
            $this->replyTo($address->address);
        }
        $this->url = $url;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Obrigações fiscais de '.CarbonImmutable::now()->translatedFormat('F \d\e Y'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.tax-obligations-digest', with: ['accountName' => $this->account]);
    }
}
