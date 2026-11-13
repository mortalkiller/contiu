<?php

namespace App\Modules\InvoiceReader\Benchmark;

use Brick\Math\BigDecimal;

class InvoicePricingService
{
    public function estimate(array $usage, array $pricing): ?string
    {
        $input = $usage['total_input_tokens'] ?? null;
        $output = $usage['total_output_tokens'] ?? null;
        $cached = $usage['total_cached_tokens'] ?? 0;
        $thoughts = $usage['total_thought_tokens'] ?? 0;
        $total = $usage['total_tokens'] ?? null;

        if (! is_int($input) || ! is_int($output) || ! is_int($total)
            || $cached > $input || $total !== $input + $output + $thoughts) {
            return null;
        }

        foreach (['input', 'cached', 'output'] as $rate) {
            if (! preg_match('/\A\d{1,5}(\.\d{1,6})?\z/', (string) ($pricing[$rate] ?? ''))) {
                return null;
            }
        }

        return (string) BigDecimal::of((string) $pricing['input'])->multipliedBy($input - $cached)
            ->plus(BigDecimal::of((string) $pricing['cached'])->multipliedBy($cached))
            ->plus(BigDecimal::of((string) $pricing['output'])->multipliedBy($output + $thoughts))
            ->dividedBy(1000000, 12);
    }
}
