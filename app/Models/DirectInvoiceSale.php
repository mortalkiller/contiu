<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

final class DirectInvoiceSale extends Model
{
    use BelongsToAccount;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'issued_on' => 'immutable_date',
            'vat_rate' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** The original invoice charge. Later debit notes can add other charges to this sale.
     *
     * @return BelongsTo<Receivable, $this>
     */
    public function receivable(): BelongsTo
    {
        return $this->belongsTo(Receivable::class);
    }

    /** @return MorphMany<Receivable, $this> */
    public function receivables(): MorphMany
    {
        return $this->morphMany(Receivable::class, 'billable');
    }
}
