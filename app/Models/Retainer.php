<?php

namespace App\Models;

use App\Enums\Frequency;
use App\Models\Concerns\HasReceivables;
use App\Models\Concerns\HasVatAmounts;
use App\Support\FinanceSettings;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Avença: a recurring charge. Each period becomes one receivable.
 */
class Retainer extends Model implements AuditableContract
{
    use Auditable, HasFactory, HasReceivables, HasVatAmounts;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'net_amount' => 'decimal:2',
            'vat_rate' => 'decimal:4',
            'frequency' => Frequency::class,
            'is_active' => 'boolean',
            'tax_exempt' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Retainer $retainer): void {
            $retainer->tax_exempt ??= app(FinanceSettings::class)->paymentsExemptByDefault();
        });

        // The retainer's exemption defines its payments': keep existing ones in line when it changes.
        static::updated(function (Retainer $retainer): void {
            if ($retainer->wasChanged('tax_exempt')) {
                Payment::query()
                    ->whereIn('receivable_id', $retainer->receivables()->select('receivables.id'))
                    ->update(['tax_exempt' => $retainer->tax_exempt]);
            }
        });
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

    /** Value per year at the current amount (e.g. 20 € monthly → 240 €). */
    protected function yearlyValue(): Attribute
    {
        return Attribute::get(fn (): float => round($this->total * (12 / $this->frequency->months()), 2));
    }

    protected function nextDueOn(): Attribute
    {
        return Attribute::get(fn () => $this->receivables
            ->filter(fn (Receivable $receivable) => $receivable->outstanding > 0)
            ->sortBy('due_on')
            ->first()?->due_on);
    }
}
