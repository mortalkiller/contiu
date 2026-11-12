<?php

namespace App\Actions;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\IntegrationOperationStatus;
use App\Exceptions\InvoicingProviderException;
use App\Mail\FiscalDocumentEmail;
use App\Models\ClientMessage;
use App\Models\FiscalDocument;
use App\Support\AccountantAccess;
use App\Support\CurrentAccount;
use App\Support\FiscalDocumentPdfs;
use App\Support\Invoicing\Invoicing;
use App\Support\Invoicing\InvoicingRecords;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Traits\Localizable;
use Illuminate\Validation\ValidationException;

/**
 * Sends an issued document to the client with its PDF attached. The PDF is the one kept with the
 * document; when there is none yet and the provider has the document, it is fetched from there
 * first. The email is queued, and noted in the client's messages and in the document's history.
 */
final class SendFiscalDocumentByEmail
{
    use Localizable;

    public function __construct(private readonly FiscalDocumentPdfs $pdfs) {}

    /** Whether the document is of the kind that can be emailed: issued by the account and not a draft or cancelled. */
    public static function canSend(FiscalDocument $document): bool
    {
        return $document->direction === FiscalDocumentDirection::Issued
            && $document->status === FiscalDocumentStatus::Active
            && $document->number !== null;
    }

    public function handle(FiscalDocument $document, string $to, ?string $message = null): void
    {
        abort_if(CurrentAccount::readOnly() || AccountantAccess::isReviewOnly(), 403);

        if (! self::canSend($document)) {
            throw ValidationException::withMessages(['document' => __('Only an issued document can be sent by email.')]);
        }

        $path = $this->pdfPath($document);
        $message = filled($message) ? trim($message) : null;
        $email = new FiscalDocumentEmail($document->loadMissing('client'), $path, $message);

        Mail::to($to)->queue($email);

        if ($document->client_id !== null) {
            ClientMessage::query()->create([
                'client_id' => $document->client_id,
                'type' => ClientMessage::FISCAL_DOCUMENT,
                'to' => $to,
                'subject' => $this->subject($email),
                'body' => (string) $message,
                'user_id' => auth()->id(),
                'sent_at' => now(),
            ]);
        }

        if (Invoicing::connection() !== null) {
            InvoicingRecords::log('emailed', IntegrationOperationStatus::Done, __('Sent by email to :email', ['email' => $to]), $document);
        }
    }

    /** Where the document's PDF is kept, fetching it from the provider when it is not kept yet. */
    private function pdfPath(FiscalDocument $document): string
    {
        $current = $document->currentPdf()->first();

        if ($current === null && $document->integrationRecord()->exists() && Invoicing::connected()) {
            try {
                $ready = $this->pdfs->retrieveFromProvider($document);
            } catch (InvoicingProviderException $exception) {
                throw ValidationException::withMessages(['document' => $exception->getMessage()]);
            }

            if (! $ready) {
                throw ValidationException::withMessages(['document' => __('The PDF is still being prepared. Try sending it again in a moment.')]);
            }

            $current = $document->currentPdf()->first();
        }

        $path = $current?->path();

        if ($path === null || ! Storage::disk(FiscalDocumentPdfs::DISK)->exists($path)) {
            throw ValidationException::withMessages(['document' => __('This document has no PDF to send yet.')]);
        }

        return $path;
    }

    /** The subject as the client reads it, in the client's language. */
    private function subject(FiscalDocumentEmail $email): string
    {
        return $this->withLocale($email->locale, fn (): string => (string) $email->envelope()->subject);
    }
}
