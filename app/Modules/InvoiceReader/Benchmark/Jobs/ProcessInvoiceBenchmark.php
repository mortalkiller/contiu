<?php

namespace App\Modules\InvoiceReader\Benchmark\Jobs;

use App\Modules\InvoiceReader\Benchmark\InvoiceBenchmarkService;
use App\Modules\InvoiceReader\Benchmark\Models\InvoiceBenchmarkRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessInvoiceBenchmark implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 80;

    public bool $failOnTimeout = true;

    public function __construct(public int $runId) {}

    public function handle(InvoiceBenchmarkService $benchmark): void
    {
        $benchmark->process($this->runId);
    }

    public function failed(?Throwable $exception): void
    {
        InvoiceBenchmarkRun::query()->whereKey($this->runId)->whereIn('status', ['queued', 'processing'])
            ->update(['status' => 'failed', 'error' => 'The benchmark worker did not complete the run.']);
    }
}
