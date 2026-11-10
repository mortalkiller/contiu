<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;

class InvoiceXpressReceiptAttempt extends Model
{
    use BelongsToAccount;

    protected $guarded = ['*'];

    protected $hidden = ['intent', 'pending_target'];

    protected function casts(): array
    {
        return [
            'intent' => 'encrypted:array',
            'provider_invoice_id' => 'integer',
            'reported_receipt_id' => 'integer',
            'provider_receipt_id' => 'integer',
        ];
    }
}
