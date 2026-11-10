<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;

class InvoiceXpressSnapshot extends Model
{
    use BelongsToAccount;

    protected $fillable = ['subdomain', 'provider_id', 'source_hash', 'source', 'fiscal_document_id', 'user_id'];

    protected function casts(): array
    {
        return [
            'provider_id' => 'integer',
            'source' => 'array',
        ];
    }
}
