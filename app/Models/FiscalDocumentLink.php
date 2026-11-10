<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalDocumentLink extends Model
{
    use BelongsToAccount;

    public const TARGET_FIELDS = ['receivable_id', 'payment_id', 'expense_id', 'related_fiscal_document_id'];

    protected $fillable = self::TARGET_FIELDS;

    /** @return BelongsTo<FiscalDocument, $this> */
    public function fiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class);
    }

    /** @return BelongsTo<Receivable, $this> */
    public function receivable(): BelongsTo
    {
        return $this->belongsTo(Receivable::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<Expense, $this> */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    /** @return BelongsTo<FiscalDocument, $this> */
    public function relatedFiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class, 'related_fiscal_document_id');
    }
}
