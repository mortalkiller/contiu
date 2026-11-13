<?php

namespace App\Modules\InvoiceReader\Benchmark;

use App\Models\Scopes\AccountScope;
use App\Modules\InvoiceReader\Benchmark\Jobs\ProcessInvoiceBenchmark;
use App\Modules\InvoiceReader\Benchmark\Models\InvoiceBenchmarkDocument;
use App\Modules\InvoiceReader\Benchmark\Models\InvoiceBenchmarkRun;
use App\Modules\InvoiceReader\InvoiceAiReader;
use App\Modules\InvoiceReader\InvoiceData;
use App\Modules\InvoiceReader\InvoiceQrCodeService;
use App\Modules\InvoiceReader\InvoiceValidationService;
use App\Modules\InvoiceReader\VertexAiProvider;
use App\Modules\InvoiceReader\ViesSupplierLookup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class InvoiceBenchmarkService
{
    public const array MODES = ['ai' => 'AI Only', 'qr_ai' => 'QR + AI', 'qr' => 'QR Only', 'demo' => 'Demo'];

    public function __construct(
        private InvoiceQrCodeService $qr,
        private InvoiceAiReader $aiReader,
        private InvoiceValidationService $validation,
        private ViesSupplierLookup $vies,
    ) {}

    public function documents(int $userId, int $accountId): Builder
    {
        return InvoiceBenchmarkDocument::query()->where('user_id', $userId)->where('account_id', $accountId)->where('expires_at', '>', now());
    }

    public function upload(UploadedFile $file, int $userId, int $accountId, ?string $fixture = null): InvoiceBenchmarkDocument
    {
        $path = $file->getPathname();
        $mime = $file->getMimeType();
        $bytes = $file->getSize();

        if (! $file->isValid() || ! in_array($mime, InvoiceQrCodeService::MIME_TYPES, true)
            || $bytes === false || $bytes < 1 || $bytes > config('invoice-benchmark.max_file_size_mb') * 1048576) {
            throw new RuntimeException('Upload a JPEG, PNG, WebP or PDF within the configured size limit.');
        }

        $dimensions = $this->qr->inspect($path, $mime);
        $hash = hash_file('sha256', $path);

        return Cache::lock("invoice-benchmark:upload:{$accountId}:{$userId}:{$hash}", 30)->block(5, function () use ($file, $userId, $accountId, $fixture, $mime, $bytes, $dimensions, $hash): InvoiceBenchmarkDocument {
            $existing = $this->documents($userId, $accountId)->where('sha256', $hash)->where('fixture', $fixture)->first();
            if ($existing !== null) {
                return $existing;
            }

            $document = new InvoiceBenchmarkDocument([
                'user_id' => $userId, 'account_id' => $accountId, 'sha256' => $hash, 'mime' => $mime,
                'bytes' => $bytes, 'dimensions' => $dimensions, 'fixture' => $fixture,
                'expires_at' => now()->addHours(config('invoice-benchmark.retention_hours')),
            ]);
            $document->id = (string) Str::uuid7();

            if (Storage::disk('local')->putFileAs('invoice-benchmark', $file, $document->id, 'private') === false) {
                throw new RuntimeException('The private document could not be stored.');
            }
            try {
                $document->save();
            } catch (Throwable $exception) {
                Storage::disk('local')->delete($document->path());

                throw $exception;
            }

            return $document;
        });
    }

    public function queue(InvoiceBenchmarkDocument $document, string $mode): InvoiceBenchmarkRun
    {
        if (! config('invoice-benchmark.enabled') || $document->expires_at->isPast()
            || ! array_key_exists($mode, self::MODES) || ($mode === 'demo' && $document->fixture === null)) {
            throw new RuntimeException('This mode is unavailable for the selected document.');
        }

        $live = in_array($mode, ['ai', 'qr_ai'], true);
        if ($live && ! config('invoice-benchmark.live')) {
            throw new RuntimeException('Live invoice AI processing is disabled.');
        }

        $run = $document->runs()->firstOrCreate(['mode' => $mode], [
            'status' => 'queued', 'provider' => $live ? 'vertex' : null,
            'model' => $live ? VertexAiProvider::MODEL : null, 'region' => $live ? 'eu' : null,
        ]);

        if ($mode === 'demo' && $run->status === 'queued') {
            $this->process($run->id);

            return $run->refresh();
        }

        $shouldQueue = $run->wasRecentlyCreated;
        if ($mode === 'qr' && $run->status === 'failed') {
            $shouldQueue = $document->runs()->whereKey($run->id)->where('status', 'failed')
                ->update(['status' => 'queued', 'error' => null]) === 1;
        }

        if ($shouldQueue) {
            try {
                ProcessInvoiceBenchmark::dispatch($run->id);
            } catch (Throwable) {
                $run->update(['status' => 'failed', 'error' => 'The benchmark job could not be queued.']);
            }
        }

        return $shouldQueue ? $run->refresh() : $run;
    }

    public function process(int $runId): void
    {
        if (InvoiceBenchmarkRun::query()->whereKey($runId)->where('status', 'queued')->update(['status' => 'processing']) !== 1) {
            return;
        }

        $run = InvoiceBenchmarkRun::query()->findOrFail($runId);
        $document = InvoiceBenchmarkDocument::withoutGlobalScope(AccountScope::class)->find($run->document_id);
        $started = hrtime(true);
        $raw = null;
        $qr = null;

        try {
            if (! config('invoice-benchmark.enabled') || $document === null || $document->expires_at->isPast()) {
                throw new RuntimeException('The benchmark is disabled or the document has expired.');
            }

            $path = $document->localPath();
            $qr = in_array($run->mode, ['qr_ai', 'qr'], true) ? $this->qr->read($path, $document->mime, $document->dimensions) : null;
            $qrData = $qr['data'] ?? [];
            $ai = [];
            if ($run->provider === 'vertex') {
                $response = Context::scope(
                    fn () => $this->aiReader->extract($path, $document->mime, $run->model, $qrData),
                    hidden: ['invoice_benchmark_run_id' => $runId],
                );
                $raw = ['text' => $response->text];
                $ai = $this->aiReader->validatedData($response);
            }

            $result = match ($run->mode) {
                'demo' => InvoiceBenchmarkFixtures::output($document->fixture),
                'qr' => $qrData,
                'qr_ai' => array_replace($ai, array_filter($qrData, fn (mixed $value, string $key): bool => InvoiceData::available($value)
                    && ($key !== 'vat' || ($ai['vat'] ?? null) === null), ARRAY_FILTER_USE_BOTH)),
                default => $ai,
            };
            $result = InvoiceData::withQrVatRates($result, $qrData);
            $review = $this->review($document, $result, $ai, $qrData);
            if (isset($result['supplier_nif'], $result['invoice_number']) && $this->duplicateIdentity($document, $result)) {
                $review['warnings'][] = 'Another benchmark upload has the same supplier NIF and invoice number.';
            }

            $hasData = InvoiceData::verifiedValues($result) !== [];
            $run->update([
                'status' => $hasData ? 'succeeded' : 'failed',
                'result' => $result, 'ai_output' => $raw, 'qr_output' => $qr, 'review' => $review,
                'error' => $hasData ? null : 'No invoice fields were extracted.',
            ]);
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed', 'ai_output' => $raw, 'qr_output' => $qr,
                'result' => $qr['data'] ?? null,
                'review' => isset($qr['data']) ? $this->review($document, $qr['data'], [], $qr['data']) : null,
                'error' => $exception instanceof RuntimeException && in_array($exception->getMessage(), [
                    'The configured Gemini model or Interactions endpoint is unavailable in the EU.',
                    'Vertex rejected the configured Google Cloud identity.',
                    'Google Application Default Credentials could not provide a Vertex access token.',
                    'Vertex reached its request quota or rate limit.',
                    'The EU Vertex request failed. Verify the project, ADC and Vertex API configuration.',
                    'Live invoice AI processing is disabled.',
                    'Vertex requires a valid Google Cloud project ID and the EU multi-region.',
                    'Vertex invoice extraction requires the approved model and a single stateless prompt.',
                    'The benchmark is disabled or the document has expired.',
                ], true) ? $exception->getMessage() : 'Extraction failed or returned an invalid invoice schema.',
            ]);
        } finally {
            $run->update(['duration_ms' => intdiv(hrtime(true) - $started, 1000000)]);
        }
    }

    private function review(InvoiceBenchmarkDocument $document, array $result, array $ai, array $qr): array
    {
        $review = $this->validation->review($result, $ai, $qr);

        if ($document->fixture === null) {
            foreach (['supplier', 'customer'] as $party) {
                $details = $this->vies->lookup($result, $party);
                if ($details !== []) {
                    $review['vies_'.$party] = $details;
                }
            }
        }

        return $review;
    }

    private function duplicateIdentity(InvoiceBenchmarkDocument $document, array $data): bool
    {
        return InvoiceBenchmarkRun::query()
            ->whereIn('document_id', $this->documents($document->user_id, $document->account_id)->withoutGlobalScope(AccountScope::class)->whereKeyNot($document->id)->select('id'))
            ->where('status', 'succeeded')->whereNotNull('result')->cursor()
            ->contains(fn (InvoiceBenchmarkRun $run): bool => InvoiceData::compare($run->result['supplier_nif'] ?? null, $data['supplier_nif'], 'supplier_nif')
                && InvoiceData::compare($run->result['invoice_number'] ?? null, $data['invoice_number'], 'invoice_number'));
    }
}
