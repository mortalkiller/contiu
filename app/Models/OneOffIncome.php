<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use App\Models\Concerns\DeletesAtomically;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Receita avulsa: a donation, refund or other income with no job or retainer behind it. It owns one
 * receivable, paid at once, and optionally the platform's commission as a paid expense. Deleting it
 * removes all of them.
 */
class OneOffIncome extends Model implements AuditableContract
{
    use Auditable, BelongsToAccount, DeletesAtomically, HasFactory;

    public const BUSINESS_LINE = 'Receitas avulsas';

    protected $guarded = ['id'];

    /** Set while an income removes its own payments, so the payment doesn't delete the income again. */
    public static bool $cascading = false;

    protected static function booted(): void
    {
        static::deleting(function (OneOffIncome $income): void {
            static::$cascading = true;

            try {
                foreach ($income->receivables()->with('payments')->get() as $receivable) {
                    $receivable->payments->each->delete();
                    $receivable->delete();
                }

                Expense::find($income->commission_expense_id)?->delete();
            } finally {
                static::$cascading = false;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'received_on' => 'date',
            'net_amount' => 'integer',
            'vat_rate' => 'decimal:4',
        ];
    }

    /** @return MorphMany<Receivable, $this> */
    public function receivables(): MorphMany
    {
        return $this->morphMany(Receivable::class, 'billable');
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<Expense, $this> */
    public function commissionExpense(): BelongsTo
    {
        return $this->belongsTo(Expense::class, 'commission_expense_id');
    }
}
