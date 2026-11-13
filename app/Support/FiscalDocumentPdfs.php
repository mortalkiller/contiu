<?php

namespace App\Support;

use App\Actions\SaveFiscalDocument;
use App\Exceptions\InvoicingProviderException;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentPdf;
use App\Support\Invoicing\Invoicing;
use App\Support\Invoicing\InvoicingRecords;
use Closure;
use finfo;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToWriteFile;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

final class FiscalDocumentPdfs
{
    public const DISK = 'local';

    public const int MAX_KIB = 10240;

    public const int MAX_BYTES = self::MAX_KIB * 1024;

    public function saveManual(array $data, ?FiscalDocument $document = null): FiscalDocument
    {
        $upload = Arr::pull($data, 'pdf_upload');
        if ($upload === null) {
            return app(SaveFiscalDocument::class)->handle($data, $document);
        }

        $this->authorizeWrite($document);
        if (! $upload instanceof TemporaryUploadedFile) {
            throw ValidationException::withMessages(['data.pdf_upload' => __('Select a PDF file.')]);
        }

        if ($upload->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages(['data.pdf_upload' => __('The PDF can be up to 10 MB.')]);
        }

        $stream = $upload->readStream();
        if (! is_resource($stream)) {
            throw ValidationException::withMessages(['data.pdf_upload' => __('Could not read the PDF.')]);
        }

        try {
            $bytes = stream_get_contents($stream, self::MAX_BYTES + 1);
        } finally {
            fclose($stream);
        }

        if (! is_string($bytes) || ! self::isPdf($bytes)) {
            throw ValidationException::withMessages(['data.pdf_upload' => __('Select a valid PDF file up to 10 MB.')]);
        }

        try {
            return $this->store($bytes, function () use ($data, $document): FiscalDocument {
                $record = app(SaveFiscalDocument::class)->handle($data, $document);
                if ($record->integrationRecord()->exists()) {
                    throw ValidationException::withMessages(['data.pdf_upload' => __('Get the PDF for this document from the invoicing provider.')]);
                }

                return $record;
            });
        } catch (FilesystemException) {
            throw ValidationException::withMessages(['data.pdf_upload' => __('Could not save the PDF.')]);
        }
    }

    /**
     * Fetches the official PDF from the invoicing provider and keeps it. False while the provider
     * is still producing it.
     */
    public function retrieveFromProvider(FiscalDocument $document): bool
    {
        $this->authorizeWrite($document);

        $entry = InvoicingRecords::for($document);
        $provider = Invoicing::provider();

        if ($entry === null || $provider === null) {
            throw new InvoicingProviderException(__('This document is not at the connected invoicing provider.'));
        }

        $bytes = $provider->pdf($entry->remote_type, $entry->remote_id);

        if ($bytes === null) {
            return false;
        }

        try {
            $this->store($bytes, function () use ($document): FiscalDocument {
                $record = FiscalDocument::query()->lockForUpdate()->findOrFail($document->getKey());
                $this->authorizeWrite($record);

                return $record;
            }, source: 'provider');
        } catch (FilesystemException) {
            throw new InvoicingProviderException(__('Could not save the official PDF.'));
        }

        return true;
    }

    /** Keeps a PDF the app already holds (an imported invoice) as the document's own. */
    public function attach(FiscalDocument $document, string $bytes): FiscalDocument
    {
        $this->authorizeWrite($document);

        if (! self::isPdf($bytes)) {
            throw ValidationException::withMessages(['data.pdf_upload' => __('Select a valid PDF file up to 10 MB.')]);
        }

        return $this->store($bytes, fn (): FiscalDocument => $document);
    }

    public static function isPdf(string $bytes): bool
    {
        if (strlen($bytes) > self::MAX_BYTES
            || preg_match('/\A%PDF-(?:1\.[0-7]|2\.0)(?:\r\n?|\n)/', $bytes) !== 1
            || preg_match('/[\r\n]startxref\s+([0-9]{1,10})\s+%%EOF\z/', rtrim($bytes, " \t\r\n\f"), $trailer) !== 1) {
            return false;
        }

        $offset = (int) $trailer[1];

        return $offset > 0 && $offset < strlen($bytes)
            && preg_match('/\G(?:xref\s|[0-9]+\s+[0-9]+\s+obj[\s<])/', $bytes, offset: $offset) === 1
            && (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) === 'application/pdf';
    }

    /** @param Closure(): FiscalDocument $resolveDocument */
    private function store(string $bytes, Closure $resolveDocument, string $source = 'upload'): FiscalDocument
    {
        $path = null;

        try {
            return DB::transaction(function () use ($bytes, $resolveDocument, $source, &$path): FiscalDocument {
                $record = $resolveDocument();
                $sha256 = hash('sha256', $bytes);
                $current = $record->currentPdf()->first();

                if ($current?->sha256 === $sha256
                    && $current->source === $source
                    && ($currentPath = $current->path()) !== null
                    && Storage::disk(self::DISK)->exists($currentPath)) {
                    return $record;
                }

                /** @var FiscalDocumentPdf $version */
                $version = $record->pdfVersions()->create([
                    'source' => $source,
                    'user_id' => auth()->id(),
                    'storage_key' => (string) Str::uuid(),
                    'sha256' => $sha256,
                    'size' => strlen($bytes),
                ]);
                $path = $version->path();

                if ($path === null || ! Storage::disk(self::DISK)->put($path, $bytes, 'private')) {
                    throw UnableToWriteFile::atLocation($path ?? 'fiscal-document-pdfs');
                }

                return $record;
            });
        } catch (Throwable $exception) {
            if ($path !== null) {
                Storage::disk(self::DISK)->delete($path);
            }

            throw $exception;
        }
    }

    private function authorizeWrite(?FiscalDocument $document): void
    {
        $account = CurrentAccount::get();
        abort_if($account === null || ($document !== null && $document->account_id !== $account->getKey()), 404);
        abort_if(CurrentAccount::readOnly() || AccountantAccess::isReviewOnly(), 403);
    }
}
