<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Models\Concerns\ExplainsBankLine;
use App\Support\FinanceSettings;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Recebimento: money that came in, allocated to a receivable.
 */
class Payment extends Model implements AuditableContract
{
    use Auditable, ExplainsBankLine, HasFactory;

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

        static::creating(function (Payment $payment): void {
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
            'amount' => 'decimal:2',
            'method' => PaymentMethod::class,
            'tax_exempt' => 'boolean',
        ];
    }

    /** @return BelongsTo<Receivable, $this> */
    public function receivable(): BelongsTo
    {
        return $this->belongsTo(Receivable::class);
    }
}
