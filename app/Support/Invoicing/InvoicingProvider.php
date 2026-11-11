<?php

namespace App\Support\Invoicing;

use App\Models\Article;
use App\Models\Client;
use App\Models\FiscalDocument;
use Carbon\CarbonInterface;
use SensitiveParameter;

/**
 * Certified invoicing software the app issues documents through. The app owns the document (header
 * and lines); the provider keeps the draft, issues it and gives back the number, the ATCUD and
 * the PDF.
 *
 * Every method that talks to the provider throws InvoicingProviderException with a message for the
 * user. Implementations never log or return the credentials.
 */
interface InvoicingProvider
{
    /** The value stored as the connection's `provider`. */
    public static function key(): string;

    public static function label(): string;

    /**
     * The fields the connection form asks for, in order.
     *
     * @return array<string, array{label: string, help?: string, secret?: bool, suffix?: string}>
     */
    public static function credentialFields(): array;

    /**
     * The credentials as they are stored, or a ValidationException naming the field that is wrong.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public static function credentials(#[SensitiveParameter] array $input): array;

    public function capabilities(): InvoicingCapabilities;

    /** Proves the credentials work. */
    public function test(): void;

    public function catalog(): InvoicingCatalog;

    /** Reads the series and taxes again, after they change at the provider. */
    public function refreshCatalog(): InvoicingCatalog;

    /** Creates the draft at the provider, or brings the one it already has up to date. */
    public function saveDraft(FiscalDocument $document, RemoteReferences $references): RemoteDocument;

    /** Turns the draft into the official document. */
    public function issue(FiscalDocument $document, string $remoteId): RemoteDocument;

    public function cancel(FiscalDocument $document, string $remoteId, string $reason): RemoteDocument;

    /** Removes a draft that will not be issued. */
    public function discard(FiscalDocument $document, string $remoteId): void;

    /** Issues the receipt for an invoice the provider holds. */
    public function settle(FiscalDocument $receipt, string $invoiceRemoteId): RemoteDocument;

    public function find(string $remoteType, string $remoteId): RemoteDocument;

    public function documents(CarbonInterface $from, CarbonInterface $until, int $page = 1): RemotePage;

    /** The official PDF, or null while the provider is still producing it. */
    public function pdf(string $remoteType, string $remoteId): ?string;

    /** Creates or updates the client at the provider and returns its id there. */
    public function saveClient(Client $client, ?string $remoteId = null): string;

    /** Creates or updates the article at the provider and returns its id there. */
    public function saveArticle(Article $article, ?string $remoteId = null): string;

    /** Where the provider shows this document, for "Open at the provider". */
    public function documentUrl(string $remoteType, string $remoteId): ?string;
}
