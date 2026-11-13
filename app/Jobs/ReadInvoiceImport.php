<?php

namespace App\Jobs;

use App\Actions\ReadImportedInvoice;
use App\Enums\InvoiceImportStatus;
use App\Models\InvoiceImport;
use App\Models\Scopes\AccountScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Reads one invoice dropped on "Importar faturas": the fiscal QR code and, when it is on, the AI. */
class ReadInvoiceImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 80;

    public bool $failOnTimeout = true;

    public function __construct(public int $importId) {}

    public function handle(ReadImportedInvoice $reader): void
    {
        $reader->handle($this->importId);
    }

    public function failed(?Throwable $exception): void
    {
        InvoiceImport::withoutGlobalScope(AccountScope::class)->whereKey($this->importId)
            ->whereIn('status', [InvoiceImportStatus::Queued, InvoiceImportStatus::Reading])
            ->toBase()
            ->update(['status' => InvoiceImportStatus::Unreadable->value, 'error' => 'failed', 'updated_at' => now()]);
    }
}
