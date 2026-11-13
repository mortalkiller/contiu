<?php

namespace App\Modules\InvoiceReader\Benchmark;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;

class InvoiceRequestMetrics
{
    public function __construct(
        private int $id,
        private int $startedAt,
        private array $pricing,
    ) {}

    public static function start(int $runId): self
    {
        $pricing = config('invoice-benchmark.pricing');
        $attempt = (int) DB::table('invoice_benchmark_requests')->where('run_id', $runId)->max('attempt') + 1;
        $id = DB::table('invoice_benchmark_requests')->insertGetId([
            'run_id' => $runId,
            'attempt' => $attempt,
            'pricing' => json_encode($pricing, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return new self($id, hrtime(true), $pricing);
    }

    public function finish(?Response $response): void
    {
        $usage = [];
        foreach (['total_input_tokens', 'total_output_tokens', 'total_thought_tokens', 'total_cached_tokens', 'total_tokens', 'total_tool_use_tokens'] as $field) {
            $count = $response?->json("usage.{$field}");
            if (is_int($count) && $count >= 0) {
                $usage[$field] = $count;
            }
        }

        DB::table('invoice_benchmark_requests')->where('id', $this->id)->update([
            'http_status' => $response?->status(),
            'duration_ms' => intdiv(hrtime(true) - $this->startedAt, 1000000),
            'usage' => $usage === [] ? null : json_encode($usage, JSON_THROW_ON_ERROR),
            'estimated_usd' => app(InvoicePricingService::class)->estimate($usage, $this->pricing),
        ]);
    }
}
