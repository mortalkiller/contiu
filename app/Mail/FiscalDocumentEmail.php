<?php

namespace App\Mail;

use App\Enums\FiscalDocumentType;
use App\Enums\Language;
use App\Mail\Concerns\SendsAsAccount;
use App\Models\FiscalDocument;
use App\Support\FiscalDocumentPdfs;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * An issued fiscal document sent to the client, with its PDF attached. Everything it needs is
 * read when it is built: the queue worker has no current account, and the document's model is
 * scoped to one, so only plain values travel through the queue.
 */
class FiscalDocumentEmail extends Mailable implements ShouldQueue
{
    use Queueable, SendsAsAccount, SerializesModels;

    public string $type;

    public string $number;

    public string $documentDate;

    public ?string $dueDate;

    public int $total;

    public int $payable;

    public string $currency;

    public string $account;

    public function __construct(
        FiscalDocument $document,
        public string $pdfPath,
        public ?string $body = null,
    ) {
        $this->type = $document->type->value;
        $this->number = (string) $document->number;
        $this->documentDate = $document->document_date->toDateString();
        $this->dueDate = $document->type->hasTerm() ? $document->due_date?->toDateString() : null;
        $this->total = $document->total_amount;
        $this->payable = $document->payableAmount();
        $this->currency = $document->currency;
        $this->account = $this->accountName();

        foreach ($this->replyToOwner() as $address) {
            $this->replyTo($address->address);
        }

        $this->locale(($document->client?->preferredLanguage() ?? Language::PortuguesePortugal)->locale());
    }

    /** The name the PDF goes by: the document's number, with only letters, digits and dashes. */
    public function attachmentName(): string
    {
        $name = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $this->number), '-');

        return ($name === '' ? 'documento' : $name).'.pdf';
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __(':type :number — :account', [
            'type' => FiscalDocumentType::from($this->type)->getLabel(),
            'number' => $this->number,
            'account' => $this->account,
        ]));
    }

    public function content(): Content
    {
        $dateFormat = Language::resolve(app()->getLocale())->dateFormat();

        return new Content(markdown: 'mail.fiscal-document', with: [
            'accountName' => $this->account,
            'typeLabel' => FiscalDocumentType::from($this->type)->getLabel(),
            'issuedOn' => CarbonImmutable::parse($this->documentDate)->format($dateFormat),
            'dueOn' => $this->dueDate === null ? null : CarbonImmutable::parse($this->dueDate)->format($dateFormat),
            'dueLabel' => match (FiscalDocumentType::from($this->type)) {
                FiscalDocumentType::Quote => __('Valid until'),
                FiscalDocumentType::ProForma => __('Pay by'),
                default => __('Due date'),
            },
        ]);
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromStorageDisk(FiscalDocumentPdfs::DISK, $this->pdfPath)
                ->as($this->attachmentName())
                ->withMime('application/pdf'),
        ];
    }
}
