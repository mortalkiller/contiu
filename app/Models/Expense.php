<?php

namespace App\Models;

use App\Enums\ExpenseKind;
use App\Enums\PaymentMethod;
use App\Models\Concerns\ExplainsBankLine;
use App\Models\Concerns\HasVatAmounts;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class Expense extends Model implements AuditableContract
{
    use Auditable, ExplainsBankLine, HasFactory, HasVatAmounts;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'spent_on' => 'date',
            'paid_on' => 'date',
            'net_amount' => 'integer',
            'vat_rate' => 'decimal:4',
            'is_paid' => 'boolean',
            'kind' => ExpenseKind::class,
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

    /** @return BelongsTo<RecurringExpense, $this> */
    public function recurringExpense(): BelongsTo
    {
        return $this->belongsTo(RecurringExpense::class);
    }

    /** The project or retainer a direct cost belongs to. */
    protected function costCentre(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->project?->code ?? $this->retainer?->code);
    }
}
