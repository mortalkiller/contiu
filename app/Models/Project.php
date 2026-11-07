<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Enums\ReceivableStatus;
use App\Models\Concerns\HasReceivables;
use App\Models\Concerns\HasVatAmounts;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Trabalho: a one-off job with an optional instalment plan (receivables).
 */
class Project extends Model implements AuditableContract
{
    use Auditable, HasFactory, HasReceivables, HasVatAmounts;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quoted_on' => 'date',
            'net_amount' => 'integer',
            'vat_rate' => 'decimal:4',
            'status' => ProjectStatus::class,
        ];
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

    /** @return HasMany<Expense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    protected function paymentStatus(): Attribute
    {
        return Attribute::get(function (): ?ReceivableStatus {
            if ($this->status === ProjectStatus::Cancelled) {
                return null;
            }

            return $this->aggregateReceivableStatus($this->total);
        });
    }
}
