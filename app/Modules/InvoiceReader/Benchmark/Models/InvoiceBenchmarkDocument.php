<?php

namespace App\Modules\InvoiceReader\Benchmark\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class InvoiceBenchmarkDocument extends Model
{
    use BelongsToAccount, HasUuids;

    protected $fillable = ['user_id', 'account_id', 'sha256', 'mime', 'bytes', 'dimensions', 'fixture', 'ground_truth', 'verified_at', 'expires_at'];

    protected function casts(): array
    {
        return [
            'dimensions' => 'array',
            'ground_truth' => 'encrypted:array',
            'verified_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(InvoiceBenchmarkRun::class, 'document_id');
    }

    public function path(): string
    {
        return "invoice-benchmark/{$this->id}";
    }

    public function localPath(): string
    {
        return Storage::disk('local')->path($this->path());
    }
}
