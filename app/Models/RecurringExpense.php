<?php

namespace App\Models;

use App\Enums\ExpenseKind;
use App\Enums\Frequency;
use App\Enums\PaymentMethod;
use App\Models\Concerns\HasVatAmounts;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Template for an expense that repeats (ChatGPT, OVH…).
 */
class RecurringExpense extends Model
{
    use HasFactory, HasVatAmounts;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'net_amount' => 'decimal:2',
            'vat_rate' => 'decimal:4',
            'is_active' => 'boolean',
            'kind' => ExpenseKind::class,
            'frequency' => Frequency::class,
            'method' => PaymentMethod::class,
        ];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Retainer, $this> */
    public function retainer(): BelongsTo
    {
        return $this->belongsTo(Retainer::class);
    }

    /** @return HasMany<Expense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    protected function yearlyCost(): Attribute
    {
        return Attribute::get(fn (): float => round($this->total * (12 / $this->frequency->months()), 2));
    }

    protected function nextOn(): Attribute
    {
        return Attribute::get(function () {
            $next = today()->day($this->day);

            return $next->isBefore(today()) ? $next->addMonthsNoOverflow($this->frequency->months()) : $next;
        });
    }
}
