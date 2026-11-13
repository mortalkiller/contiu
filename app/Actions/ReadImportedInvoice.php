<?php

namespace App\Actions;

use App\Enums\InvoiceImportStatus;
use App\Models\Account;
use App\Models\InvoiceImport;
use App\Models\Scopes\AccountScope;
use App\Modules\InvoiceReader\InvoiceAiReader;
use App\Modules\InvoiceReader\InvoiceData;
use App\Modules\InvoiceReader\InvoiceQrCodeService;
use App\Modules\InvoiceReader\VertexAiProvider;
use App\Support\CurrentAccount;
use App\Support\ExpenseDocumentQr;
use App\Support\InvoiceEmailPdfQr;
use App\Support\InvoiceImportDraft;
use DomainException;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Reads an invoice dropped on "Importar faturas". The fiscal QR code is exact and always read; the
 * AI, when it is on, reads the rest (the supplier's name, the lines). What comes out is a draft the
 * person confirms: nothing becomes an expense here.
 */
final class ReadImportedInvoice
{
    public function __construct(
        private InvoiceQrCodeService $qr,
        private InvoiceAiReader $ai,
        private ExpenseDocumentQr $photoQr,
        private InvoiceEmailPdfQr $pdfQr,
        private DecideInvoiceImport $decide,
    ) {}

    /** Whether the AI reads the invoices, or only their QR code. */
    public static function usesAi(): bool
    {
        return (bool) config('invoice-benchmark.enabled') && (bool) config('invoice-benchmark.live');
    }

    public function handle(int $importId): void
    {
        $query = fn () => InvoiceImport::withoutGlobalScope(AccountScope::class)->whereKey($importId);

        // Mass updates on purpose: a job has no one signed in, and the reading must never be lost to a model rule.
        if ($query()->where('status', InvoiceImportStatus::Queued)->toBase()->update(['status' => InvoiceImportStatus::Reading->value, 'updated_at' => now()]) !== 1) {
            return;
        }

        $import = $query()->firstOrFail();
        $account = Account::query()->findOrFail($import->account_id);

        try {
            $result = CurrentAccount::run($account, fn (): array => $this->read($import));
        } catch (Throwable $exception) {
            report($exception);
            $result = ['status' => InvoiceImportStatus::Unreadable, 'error' => 'failed'];
        }

        $query()->toBase()->update([
            'status' => $result['status']->value,
            'data' => isset($result['data']) ? json_encode($result['data'], JSON_THROW_ON_ERROR) : null,
            'outcome' => isset($result['outcome']) ? json_encode($result['outcome'], JSON_THROW_ON_ERROR) : null,
            'error' => $result['error'] ?? null,
            'handled_at' => $result['status']->isHandled() ? now() : null,
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array{status: InvoiceImportStatus, data?: array<string, mixed>, outcome?: array<string, mixed>, error?: string}
     */
    private function read(InvoiceImport $import): array
    {
        $disk = Storage::disk(InvoiceImport::DISK);
        if (! $disk->exists($import->storagePath())) {
            return ['status' => InvoiceImportStatus::Unreadable, 'error' => 'failed'];
        }

        $path = $disk->path($import->storagePath());
        $qr = $this->qrData($import, $path);
        $ai = [];

        if (self::usesAi()) {
            try {
                $ai = $this->ai->validatedData($this->ai->extract($path, $import->mime, VertexAiProvider::MODEL, $qr));
            } catch (Throwable $exception) {
                // Without the AI the QR code still gives the amounts: the person fills in the rest.
                report($exception);
            }
        }

        $result = InvoiceData::withQrVatRates(array_replace($ai, array_filter($qr, InvoiceData::available(...))), $qr);
        $draft = InvoiceImportDraft::fromReading($result, $qr);

        if ($draft === null) {
            return ['status' => InvoiceImportStatus::Unreadable, 'error' => 'not_invoice'];
        }

        if ($draft['total'] === null || $draft['total'] <= 0 || $draft['net'] === null || $draft['net'] < 0
            || ($draft['supplier_nif'] === null && $draft['supplier_name'] === null)) {
            return ['status' => InvoiceImportStatus::Unreadable, 'error' => self::usesAi() ? 'unreadable' : 'no_qr'];
        }

        $import->data = $draft;
        $decision = $this->decide->handle($import);

        return $decision['kind'] === 'duplicate'
            ? ['status' => InvoiceImportStatus::Duplicate, 'data' => $draft, 'outcome' => ['kind' => 'duplicate', 'total' => $draft['total']]]
            : ['status' => InvoiceImportStatus::Read, 'data' => $draft];
    }

    /**
     * The fiscal QR code of the file: the module's reader first, then the app's own decoders,
     * which need nothing installed on the server.
     *
     * @return array<string, mixed>
     */
    private function qrData(InvoiceImport $import, string $path): array
    {
        $data = $this->qr->read($path, $import->mime, $import->dimensions)['data'] ?? null;
        if (is_array($data)) {
            return $data;
        }

        try {
            $bytes = (string) file_get_contents($path);
            $text = $import->isPdf() ? $this->pdfQr->read($bytes) : $this->photoQr->readImage($bytes);
        } catch (DomainException) {
            return [];
        }

        return is_string($text) ? ($this->qr->parse(trim($text)) ?? []) : [];
    }
}
