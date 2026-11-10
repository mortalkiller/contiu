<?php

namespace App\Models;

use App\Enums\BankTransactionStatus;
use App\Enums\DifferenceExplanation;
use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

/**
 * One line of an imported bank statement. It ends explained: linked to the records it stands for
 * (Registado), money between Pedro's own accounts (Transferência minha), or ignored.
 */
class BankTransaction extends Model
{
    use BelongsToAccount;

    protected $guarded = ['id'];

    /** @var array<string, string> */
    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'booked_on' => 'date',
            'amount' => 'integer',
            'balance' => 'integer',
            'status' => BankTransactionStatus::class,
            'difference_explanation' => DifferenceExplanation::class,
        ];
    }

    /** @return HasMany<BankTransactionLink, $this> */
    public function links(): HasMany
    {
        return $this->hasMany(BankTransactionLink::class);
    }

    /** @return HasOne<CustomerRefund, $this> */
    public function customerRefund(): HasOne
    {
        return $this->hasOne(CustomerRefund::class);
    }

    /** @return Collection<int, Payment|Expense> */
    public function records(): Collection
    {
        return $this->links()->with('linkable')->get()->pluck('linkable')->filter()->values();
    }

    /** Line amount minus what the linked records add up to (payments +, expenses −). */
    public function difference(): int
    {
        $linked = $this->records()->sum(fn (Payment|Expense $record) => $record instanceof Payment ? $record->amount : -$record->total);

        return (int) $this->amount - (int) $linked + (int) $this->customerRefund?->amount;
    }

    /** @param Builder<BankTransaction> $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', BankTransactionStatus::Pending);
    }

    protected function isCredit(): Attribute
    {
        return Attribute::get(fn (): bool => (int) $this->amount > 0);
    }

    protected function absoluteAmount(): Attribute
    {
        return Attribute::get(fn (): int => abs((int) $this->amount));
    }
}
