<?php

namespace App\Models\Concerns;

use App\Enums\ReceivableStatus;
use App\Exceptions\HasPaymentsException;
use App\Models\Payment;
use App\Models\Receivable;
use App\Models\Retainer;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Shared by projects (instalments) and retainers (periods).
 */
trait HasReceivables
{
    use DeletesAtomically;

    /**
     * Receivables are polymorphic (no foreign key), so they are removed with their owner here —
     * unless money was already received against any of them.
     */
    protected static function bootHasReceivables(): void
    {
        static::deleting(function (self $owner): void {
            if ($owner->receivables()->whereHas('payments')->exists()) {
                throw HasPaymentsException::for($owner instanceof Retainer ? __('this retainer') : __('this project'));
            }

            $owner->receivables()->get()->each->delete();
        });
    }

    /** @return MorphMany<Receivable, $this> */
    public function receivables(): MorphMany
    {
        return $this->morphMany(Receivable::class, 'billable')->orderBy('due_on')->orderBy('sort');
    }

    /** @return HasManyThrough<Payment, Receivable, $this> */
    public function payments(): HasManyThrough
    {
        return $this->hasManyThrough(Payment::class, Receivable::class, 'billable_id', 'receivable_id')
            ->where('receivables.billable_type', static::class);
    }

    protected function billedAmount(): Attribute
    {
        return Attribute::get(fn (): int => (int) $this->receivables->sum('amount'));
    }

    protected function receivedAmount(): Attribute
    {
        return Attribute::get(fn (): int => (int) $this->receivables->sum(fn (Receivable $receivable) => $receivable->received));
    }

    protected function outstandingAmount(): Attribute
    {
        return Attribute::get(fn (): int => (int) $this->receivables->sum(fn (Receivable $receivable) => $receivable->projectedOutstanding()));
    }

    protected function overdueAmount(): Attribute
    {
        return Attribute::get(fn (): int => (int) $this->receivables
            ->filter(fn (Receivable $receivable) => $receivable->due_on?->isBefore(today()))
            ->sum(fn (Receivable $receivable) => $receivable->projectedOutstanding()));
    }

    protected function directCosts(): Attribute
    {
        return Attribute::get(fn (): int => (int) $this->expenses->sum(fn ($expense) => $expense->total));
    }

    protected function margin(): Attribute
    {
        return Attribute::get(fn (): int => $this->billed_amount - $this->direct_costs);
    }

    protected function progress(): Attribute
    {
        return Attribute::get(fn (): float => $this->billed_amount > 0 ? min(1, $this->received_amount / $this->billed_amount) : 0);
    }

    protected function aggregateReceivableStatus(int $expected): ReceivableStatus
    {
        if ($this->received_amount >= $expected) {
            return ReceivableStatus::Paid;
        }

        if ($this->overdue_amount > 0) {
            return ReceivableStatus::Overdue;
        }

        return $this->received_amount > 0 ? ReceivableStatus::Partial : ReceivableStatus::Open;
    }
}
