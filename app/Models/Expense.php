<?php

namespace App\Models;

use App\Enums\ExpenseAttribution;
use App\Enums\ExpenseKind;
use App\Enums\PaymentMethod;
use App\Models\Concerns\BelongsToAccount;
use App\Models\Concerns\ExplainsBankLine;
use App\Models\Concerns\HasVatAmounts;
use App\Support\ExpenseEvidence;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class Expense extends Model implements AuditableContract
{
    use Auditable, BelongsToAccount, ExplainsBankLine, HasFactory, HasVatAmounts;

    protected $guarded = ['id', 'proof_path'];

    protected $hidden = ['proof_path'];

    protected $auditExclude = ['proof_path'];

    protected static function booted(): void
    {
        static::deleted(function (Expense $expense): void {
            $path = ExpenseEvidence::path($expense);

            if ($path !== null) {
                $expense->getConnection()->afterCommit(fn () => Storage::disk(ExpenseEvidence::DISK)->delete($path));
            }
        });
    }

    protected function casts(): array
    {
        return [
            'spent_on' => 'date',
            'paid_on' => 'date',
            'net_amount' => 'integer',
            'vat_rate' => 'decimal:4',
            'is_paid' => 'boolean',
            'is_goods' => 'boolean',
            'kind' => ExpenseKind::class,
            'method' => PaymentMethod::class,
            'attribution' => ExpenseAttribution::class,
            'is_reviewed' => 'boolean',
        ];
    }

    public function hasProof(): bool
    {
        return ExpenseEvidence::path($this) !== null;
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
