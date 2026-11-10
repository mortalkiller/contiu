<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Database\Factories\CreditApplicationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CreditApplication extends Model
{
    use BelongsToAccount;

    /** @use HasFactory<CreditApplicationFactory> */
    use HasFactory;

    protected $fillable = [
        'receivable_id', 'invoice_id', 'credit_note_id', 'amount',
        'applied_on', 'applied_by', 'reversed_on', 'reversed_by', 'reversal_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'applied_on' => 'immutable_date',
            'reversed_on' => 'immutable_date',
        ];
    }

    /** @param Builder<CreditApplication> $query */
    public function scopeEffectiveAt(Builder $query, string $date): void
    {
        $query->whereDate('applied_on', '<=', $date)
            ->where(fn (Builder $query): Builder => $query->whereNull('reversed_on')->orWhereDate('reversed_on', '>', $date));
    }
}
