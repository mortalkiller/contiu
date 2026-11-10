<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerRefund extends Model
{
    use BelongsToAccount;

    protected $fillable = ['receivable_id', 'invoice_id', 'credit_note_id', 'bank_transaction_id', 'amount', 'paid_on', 'reference', 'recorded_by'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'paid_on' => 'immutable_date'];
    }

    /** @return BelongsTo<FiscalDocument, $this> */
    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class, 'credit_note_id');
    }
}
