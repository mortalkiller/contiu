<?php

namespace App\Console\Commands;

use App\Models\InvoiceImport;
use App\Models\Scopes\AccountScope;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('invoice-imports:prune')]
#[Description('Delete the files of invoices dropped on "Importar faturas" once they are dealt with, or long forgotten')]
class PruneInvoiceImports extends Command
{
    /**
     * An invoice confirmed keeps its file where it belongs (the expense, the fiscal document): the
     * copy in the inbox goes after a while. One never confirmed goes too, much later.
     */
    public function handle(): int
    {
        $disk = Storage::disk(InvoiceImport::DISK);
        $deleted = 0;

        InvoiceImport::withoutGlobalScope(AccountScope::class)
            ->where(fn ($query) => $query
                ->where('handled_at', '<=', now()->subDays(InvoiceImport::KEEP_HANDLED_DAYS))
                ->orWhere(fn ($query) => $query->whereNull('handled_at')->where('updated_at', '<=', now()->subDays(InvoiceImport::KEEP_WAITING_DAYS))))
            ->eachById(function (InvoiceImport $import) use ($disk, &$deleted): void {
                // The row goes only with its file; a mass delete, as no one is signed in here.
                if (! $disk->exists($import->storagePath()) || $disk->delete($import->storagePath())) {
                    $deleted += InvoiceImport::withoutGlobalScope(AccountScope::class)->whereKey($import->id)->toBase()->delete();
                }
            });

        $this->info("Deleted {$deleted} invoice imports.");

        return self::SUCCESS;
    }
}
