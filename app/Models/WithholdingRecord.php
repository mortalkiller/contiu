<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WithholdingRecord extends Model
{
    use BelongsToAccount;

    protected $fillable = [
        'fiscal_document_id', 'payment_id', 'paid_on', 'gross_amount', 'retained_amount', 'source_hash',
        'payment_allocation_id', 'receivable_id', 'original_document_id', 'receivable_amount',
        'document_net_amount', 'document_date', 'document_number', 'settles_receivable',
    ];

    protected function casts(): array
    {
        return [
            'payment_id' => 'integer',
            'payment_allocation_id' => 'integer',
            'receivable_id' => 'integer',
            'settles_receivable' => 'boolean',
            'paid_on' => 'immutable_date',
            'gross_amount' => 'integer',
            'retained_amount' => 'integer',
        ];
    }

    /** @return BelongsTo<FiscalDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class, 'fiscal_document_id');
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
