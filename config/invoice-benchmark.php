<?php

return [
    'enabled' => (bool) env('INVOICE_AI_ENABLED', false),
    'live' => (bool) env('INVOICE_AI_LIVE_ENABLED', false),
    'project' => env('GOOGLE_CLOUD_PROJECT_ID'),
    'location' => env('GOOGLE_CLOUD_LOCATION', 'eu'),
    'provider' => env('INVOICE_AI_PROVIDER', 'vertex'),
    'model' => env('INVOICE_AI_MODEL', 'gemini-3.1-flash-lite'),
    'max_file_size_mb' => min(10, max(1, (int) env('INVOICE_AI_MAX_FILE_SIZE_MB', 10))),
    'retention_hours' => max(1, (int) env('INVOICE_AI_RETENTION_HOURS', 24)),
    'pricing' => [
        'version' => env('INVOICE_AI_PRICING_VERSION', 'google-standard-eu-2026-11-13'),
        'source' => 'https://cloud.google.com/model-garden/pricing',
        'input' => env('INVOICE_AI_USD_INPUT_PER_MILLION', '0.275'),
        'cached' => env('INVOICE_AI_USD_CACHED_PER_MILLION', '0.0275'),
        'output' => env('INVOICE_AI_USD_OUTPUT_PER_MILLION', '1.65'),
    ],
];
