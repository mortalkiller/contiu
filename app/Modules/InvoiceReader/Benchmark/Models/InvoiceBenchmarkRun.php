<?php

namespace App\Modules\InvoiceReader\Benchmark\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceBenchmarkRun extends Model
{
    protected $fillable = ['mode', 'status', 'provider', 'model', 'region', 'duration_ms', 'result', 'ai_output', 'qr_output', 'review', 'error'];

    protected function casts(): array
    {
        return [
            'result' => 'encrypted:array',
            'ai_output' => 'encrypted:array',
            'qr_output' => 'encrypted:array',
            'review' => 'encrypted:array',
            'duration_ms' => 'integer',
        ];
    }
}
