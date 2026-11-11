<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Models\Concerns\BelongsToAccount;
use App\Models\Concerns\DeletesAtomically;
use App\Models\Concerns\ExplainsBankLine;
use App\Support\FinanceSettings;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Recebimento: money that came in, allocated to a receivable.
 */
class Payment extends Model implements AuditableContract
{
    use Auditable, BelongsToAccount, DeletesAtomically, ExplainsBankLine, HasFactory;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        // Unless chosen explicitly: a retainer's payment follows the retainer; any other follows the
        // "Recebimentos isentos por defeito" setting.
        // A one-off income is its payment: deleting the payment removes the income (receivable and
        // commission), and a new amount carries over so nothing is left open.
        static::deleted(function (Payment $payment): void {
            $income = $payment->receivable?->billable;

            if ($income instanceof OneOffIncome && ! OneOffIncome::$cascading) {
                $income->delete();
            }
        });

        static::updated(function (Payment $payment): void {
            $income = $payment->receivable?->billable;

            if ($income instanceof OneOffIncome && $payment->wasChanged('amount')) {
                $payment->receivable->update(['amount' => $payment->amount]);
                $income->update(['net_amount' => $payment->amount]);
            }
        });

        static::updating(function (Payment $payment): void {
            if ($payment->isDirty('advance_client_id')
                || ($payment->isAdvance() && $payment->isDirty(['amount', 'paid_on', 'receivable_id', 'is_split']))) {
                throw ValidationException::withMessages(['amount' => __('The date, client, and amount of an advance cannot be changed.')]);
            }

            if ($payment->isDirty('is_split') || ($payment->is_split && $payment->isDirty(['amount', 'receivable_id']))) {
                throw ValidationException::withMessages(['amount' => __('The amount of a split payment is set by its allocations.')]);
            }
        });

        static::saved(function (Payment $payment): void {
            if ($payment->isAdvance()) {
                return;
            }

            if ($payment->is_split) {
                if ($payment->wasChanged('paid_on')) {
                    $payment->allocations()->update(['allocated_on' => $payment->paid_on->toDateString()]);
                }

                return;
            }

            $payment->allocations()->where('receivable_id', '!=', $payment->receivable_id)->delete();
            $payment->allocations()->updateOrCreate(['receivable_id' => $payment->receivable_id], [
                'amount' => $payment->amount,
                'allocated_on' => $payment->paid_on,
            ]);
        });

        static::creating(function (Payment $payment): void {
            if ($payment->isAdvance() === filled($payment->receivable_id) || ($payment->isAdvance() && $payment->is_split)) {
                throw ValidationException::withMessages(['receivable_id' => __('The payment must specify a receivable or the client for the advance.')]);
            }

            if ($payment->tax_exempt === null) {
                $billable = $payment->receivable?->billable;
                $payment->tax_exempt = $billable instanceof Retainer
                    ? (bool) $billable->tax_exempt
                    : app(FinanceSettings::class)->paymentsExemptByDefault();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'paid_on' => 'date',
            'amount' => 'integer',
            'method' => PaymentMethod::class,
            'tax_exempt' => 'boolean',
            'is_split' => 'boolean',
        ];
    }

    public function save(array $options = []): bool
    {
        return $this->getConnection()->transaction(fn (): bool => parent::save($options));
    }

    /** @return HasMany<PaymentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function receivableLabels(): string
    {
        return $this->isAdvance() && $this->allocations->isEmpty()
            ? 'Sinal por afetar · '.$this->client?->name
            : $this->allocations->map(fn (PaymentAllocation $allocation): string => $allocation->receivable->label)->implode(' · ');
    }

    public function isAdvance(): bool
    {
        return $this->advance_client_id !== null;
    }

    protected function client(): Attribute
    {
        return Attribute::get(fn (): ?Client => $this->isAdvance() ? $this->advanceClient : $this->receivable?->client);
    }

    /** @return BelongsTo<Client, $this> */
    public function advanceClient(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** Compatibility anchor for legacy single-payment writers and the client of a split.
     *
     * @return BelongsTo<Receivable, $this>
     */
    public function receivable(): BelongsTo
    {
        return $this->belongsTo(Receivable::class);
    }
}
