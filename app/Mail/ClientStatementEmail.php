<?php

namespace App\Mail;

use App\Exports\ClientStatementExport;
use App\Mail\Concerns\SendsAsAccount;
use App\Support\ClientStatement;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Extrato por email: a short summary in the body with the full statement attached as .xlsx.
 */
class ClientStatementEmail extends Mailable
{
    use Queueable, SendsAsAccount, SerializesModels;

    public function __construct(
        public ClientStatement $statement,
        public string $subjectLine,
        public string $body,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine, replyTo: $this->replyToOwner());
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.client-statement', with: [
            'accountName' => $this->accountName(),
            'charged' => $this->statement->charged(),
            'received' => $this->statement->received(),
            'balance' => $this->statement->balance(),
            'overdue' => $this->statement->overdueBalance(),
        ]);
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(
                fn () => Excel::raw(new ClientStatementExport($this->statement), ExcelWriter::XLSX),
                ClientStatementExport::filename($this->statement),
            )->withMime('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ];
    }
}
