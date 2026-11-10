<?php

namespace App\Support;

use App\Actions\SaveFiscalDocument;
use App\Exceptions\InvoiceXpressException;
use App\Models\Account;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentPdf;
use App\Models\InvoiceXpressConfig;
use App\Models\InvoiceXpressSnapshot;
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
            throw ValidationException::withMessages(['data.pdf_upload' => 'Seleciona um ficheiro PDF.']);
        }

        if ($upload->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages(['data.pdf_upload' => 'O PDF pode ter até 10 MB.']);
        }

        $stream = $upload->readStream();
        if (! is_resource($stream)) {
            throw ValidationException::withMessages(['data.pdf_upload' => 'Não foi possível ler o PDF.']);
        }

        try {
            $bytes = stream_get_contents($stream, self::MAX_BYTES + 1);
        } finally {
            fclose($stream);
        }

        if (! is_string($bytes) || ! self::isPdf($bytes)) {
            throw ValidationException::withMessages(['data.pdf_upload' => 'Seleciona um ficheiro PDF válido de até 10 MB.']);
        }

        try {
            return $this->store($bytes, function () use ($data, $document): FiscalDocument {
                $record = app(SaveFiscalDocument::class)->handle($data, $document);
                if ($record->invoiceXpressSnapshot()->exists()) {
                    throw ValidationException::withMessages(['data.pdf_upload' => 'Obtém o PDF deste documento através do InvoiceXpress.']);
                }

                return $record;
            });
        } catch (FilesystemException) {
            throw ValidationException::withMessages(['data.pdf_upload' => 'Não foi possível guardar o PDF.']);
        }
    }

    public function retrieveOfficial(FiscalDocument $document): bool
    {
        $this->authorizeWrite($document);

        $snapshot = $document->invoiceXpressSnapshot()->first();
        $config = InvoiceXpressConfig::current();
        if ($snapshot === null || $config === null || $snapshot->subdomain !== $config->subdomain) {
            throw new InvoiceXpressException('O documento não está ligado à conta InvoiceXpress atual.');
        }

        $bytes = app(InvoiceXpressFiscalPdf::class)->retrieve($config, $snapshot->provider_id);
        if ($bytes === null) {
            return false;
        }

        try {
            $this->store($bytes, function () use ($document, $snapshot): FiscalDocument {
                Account::query()->lockForUpdate()->findOrFail(CurrentAccount::id());
                $record = FiscalDocument::query()->lockForUpdate()->findOrFail($document->getKey());
                $this->authorizeWrite($record);

                if ($record->invoiceXpressSnapshot()->first()?->id !== $snapshot->id
                    || InvoiceXpressConfig::current()?->subdomain !== $snapshot->subdomain) {
                    throw new InvoiceXpressException('A ligação do documento ao InvoiceXpress mudou. Tenta novamente.');
                }

                return $record;
            }, $snapshot);
        } catch (FilesystemException) {
            throw new InvoiceXpressException('Não foi possível guardar o PDF oficial.');
        }

        return true;
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
    private function store(string $bytes, Closure $resolveDocument, ?InvoiceXpressSnapshot $snapshot = null): FiscalDocument
    {
        $path = null;

        try {
            return DB::transaction(function () use ($bytes, $resolveDocument, $snapshot, &$path): FiscalDocument {
                $record = $resolveDocument();
                $sha256 = hash('sha256', $bytes);
                $current = $record->currentPdf()->first();

                if ($current?->sha256 === $sha256
                    && $current->invoice_xpress_snapshot_id === $snapshot?->id
                    && ($currentPath = $current->path()) !== null
                    && Storage::disk(self::DISK)->exists($currentPath)) {
                    return $record;
                }

                /** @var FiscalDocumentPdf $version */
                $version = $record->pdfVersions()->create([
                    'invoice_xpress_snapshot_id' => $snapshot?->id,
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
