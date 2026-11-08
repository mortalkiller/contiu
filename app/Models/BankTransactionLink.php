<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One record (payment or expense) that explains a bank line. created_from_line and marked_paid say
 * what "Voltar a tratar" has to undo.
 */
class BankTransactionLink extends Model
{
    use BelongsToAccount;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'created_from_line' => 'boolean',
            'marked_paid' => 'boolean',
        ];
    }

    /** @return BelongsTo<BankTransaction, $this> */
    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class);
    }

    /** @return MorphTo<Payment|Expense, $this> */
    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }
}
