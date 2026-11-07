<?php

namespace App\Mail;

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
    use Queueable, SerializesModels;

    /** @var Collection<int, array{code: string, description: string, due_on: string, outstanding: float, overdue: bool, highlighted: bool}> */
    public Collection $items;

    public float $totalOpen;

    public float $totalOverdue;

    public function __construct(
        public Client $client,
        public string $subjectLine,
        public string $body,
        public ?int $highlightedId = null,
    ) {
        $this->items = $client->openReceivables()->map(fn (Receivable $receivable) => [
            'code' => (string) $receivable->billable?->code,
            'description' => $receivable->description,
            'due_on' => $receivable->due_on->format('d/m/Y'),
            'outstanding' => $receivable->outstanding,
            'overdue' => $receivable->due_on->isBefore(today()),
            'highlighted' => $receivable->getKey() === $highlightedId,
        ])->values();

        $this->totalOpen = round((float) $this->items->sum('outstanding'), 2);
        $this->totalOverdue = round((float) $this->items->where('overdue', true)->sum('outstanding'), 2);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.payment-reminder');
    }
}
