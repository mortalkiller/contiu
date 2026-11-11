<?php

namespace App\Mail;

use App\Mail\Concerns\SendsAsAccount;
use App\Models\Client;
use App\Models\Receivable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Lembrete de pagamento: the client's open amounts, overdue ones flagged. Only ever sent from the
 * review modal (SendPaymentReminderAction), never automatically.
 */
class PaymentReminder extends Mailable
{
    use Queueable, SendsAsAccount, SerializesModels;

    /** @var Collection<int, array{code: string, description: string, due_on: string, outstanding: int, overdue: bool, highlighted: bool}> */
    public Collection $items;

    public int $totalOpen; // cents

    public int $totalOverdue;

    public function __construct(
        public Client $client,
        public string $subjectLine,
        public string $body,
        public ?int $highlightedId = null,
    ) {
        $language = $client->preferredLanguage();
        $this->locale($language->locale());
        $this->items = $client->openReceivables()->map(fn (Receivable $receivable) => [
            'code' => (string) $receivable->billable?->code,
            'description' => $receivable->description,
            'due_on' => $receivable->due_on->format($language->dateFormat()),
            'outstanding' => $receivable->outstanding,
            'overdue' => $receivable->due_on->isBefore(today()),
            'highlighted' => $receivable->getKey() === $highlightedId,
        ])->values();

        $this->totalOpen = (int) $this->items->sum('outstanding');
        $this->totalOverdue = (int) $this->items->where('overdue', true)->sum('outstanding');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine, replyTo: $this->replyToOwner());
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.payment-reminder', with: ['accountName' => $this->accountName()]);
    }
}
