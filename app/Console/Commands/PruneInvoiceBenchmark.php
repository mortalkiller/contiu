<?php

namespace App\Console\Commands;

use App\Models\InvoiceBenchmarkDocument;
use App\Models\Scopes\AccountScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class PruneInvoiceBenchmark extends Command
{
    protected $signature = 'invoice-benchmark:prune';

    protected $description = 'Delete expired invoice benchmark documents, results and temporary PDF previews';

    public function handle(): int
    {
        $disk = Storage::disk('local');

        InvoiceBenchmarkDocument::withoutGlobalScope(AccountScope::class)->where('expires_at', '<=', now())->each(function (InvoiceBenchmarkDocument $document) use ($disk): void {
            if ($disk->delete($document->path())) {
                InvoiceBenchmarkDocument::withoutGlobalScope(AccountScope::class)->whereKey($document->id)->delete();
            }
        });

        $rasterPath = $disk->path('invoice-benchmark-raster');
        foreach (File::isDirectory($rasterPath) ? File::directories($rasterPath) : [] as $directory) {
            if (File::lastModified($directory) < now()->subHour()->timestamp) {
                File::deleteDirectory($directory);
            }
        }

        foreach ($disk->files('invoice-benchmark') as $path) {
            if ($disk->lastModified($path) < now()->subHour()->timestamp
                && ! InvoiceBenchmarkDocument::withoutGlobalScope(AccountScope::class)->whereKey(basename($path))->exists()) {
                $disk->delete($path);
            }
        }

        return self::SUCCESS;
    }
}
