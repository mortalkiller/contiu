<?php

namespace App\Actions;

use App\Enums\InvoiceImportStatus;
use App\Exceptions\AccountIsReadOnly;
use App\Jobs\ReadInvoiceImport;
use App\Models\Account;
use App\Models\InvoiceImport;
use App\Modules\InvoiceReader\InvoiceQrCodeService;
use App\Support\CurrentAccount;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Takes an invoice dropped on "Importar faturas": keeps the file private to the account and
 * queues its reading. The same file dropped twice is the same invoice.
 */
final class ReceiveInvoiceImport
{
    public const int MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(private InvoiceQrCodeService $qr) {}

    public function handle(UploadedFile $file): InvoiceImport
    {
        if (CurrentAccount::readOnly()) {
            throw new AccountIsReadOnly;
        }

        $path = $file->getPathname();
        $mime = $file->getMimeType();
        $bytes = $file->getSize();

        if (! $file->isValid() || ! in_array($mime, InvoiceQrCodeService::MIME_TYPES, true)
            || $bytes === false || $bytes < 1 || $bytes > self::MAX_BYTES) {
            throw new DomainException(__('Choose a PDF or a JPEG, PNG or WebP photo up to 10 MB.'));
        }

        try {
            $dimensions = $this->qr->inspect($path, $mime);
        } catch (RuntimeException) {
            throw new DomainException($mime === 'application/pdf'
                ? __('This PDF cannot be opened. Choose one without a password and with up to 20 pages.')
                : __('This photo cannot be opened. Choose one with up to 25 megapixels.'));
        }

        $hash = hash_file('sha256', $path);
        $name = Str::limit(trim((string) preg_replace('/[\p{C}\/\\\\]+/u', ' ', $file->getClientOriginalName())), 200, '') ?: __('invoice');

        $queued = false;
        $import = DB::transaction(function () use ($file, $mime, $bytes, $dimensions, $hash, $name, &$queued): InvoiceImport {
            Account::query()->lockForUpdate()->findOrFail(CurrentAccount::id());
            $existing = InvoiceImport::query()->where('sha256', $hash)->first();
            $disk = Storage::disk(InvoiceImport::DISK);

            // Already here and still of use: the same invoice, not a second one.
            if ($existing !== null && ! in_array($existing->status, [InvoiceImportStatus::Dismissed, InvoiceImportStatus::Unreadable], true)
                && $disk->exists($existing->storagePath())) {
                return $existing;
            }

            $import = $existing ?? new InvoiceImport(['file_id' => (string) Str::uuid(), 'sha256' => $hash]);
            $import->fill([
                'user_id' => auth()->id(), 'filename' => $name, 'mime' => $mime, 'bytes' => $bytes, 'dimensions' => $dimensions,
                'status' => InvoiceImportStatus::Queued, 'data' => null, 'choices' => null, 'outcome' => null, 'error' => null, 'handled_at' => null,
            ]);
            $import->account_id ??= CurrentAccount::id();

            if ($disk->putFileAs(dirname($import->storagePath()), $file, $import->file_id, 'private') === false) {
                throw new DomainException(__('Could not save the file.'));
            }

            try {
                $import->save();
            } catch (Throwable $exception) {
                if ($existing === null) {
                    $disk->delete($import->storagePath());
                }

                throw $exception;
            }

            $queued = true;

            return $import;
        });

        if ($queued) {
            ReadInvoiceImport::dispatch($import->id);
        }

        return $import;
    }
}
