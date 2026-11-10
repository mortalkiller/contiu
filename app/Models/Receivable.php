<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Enums\ReceivableStatus;
use App\Exceptions\HasPaymentsException;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Retainers\RetainerResource;
use App\Models\Concerns\BelongsToAccount;
use App\Models\Scopes\AccountScope;
use App\Support\WithholdingReview;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Valor a receber: one instalment of a project or one period of a retainer.
 */
class Receivable extends Model implements AuditableContract
{
    use Auditable, BelongsToAccount, HasFactory;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::deleting(function (Receivable $receivable): void {
            if ($receivable->payments()->exists()) {
                throw HasPaymentsException::for('este valor a receber');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'due_on' => 'date',
        ];
    }

    /** @return MorphTo<Project|Retainer, $this> */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsToMany<Payment, $this> */
    public function payments(): BelongsToMany
    {
        return $this->belongsToMany(Payment::class, 'payment_allocations')->withPivot(['amount', 'allocated_on'])->orderBy('paid_on');
    }

    /** @return HasMany<CreditApplication, $this> */
    public function creditApplications(): HasMany
    {
        return $this->hasMany(CreditApplication::class);
    }

    /** @param Builder<Receivable> $query */
    public function scopeForClient(Builder $query, int $clientId): void
    {
        $query->where(fn (Builder $query) => $query
            ->whereHasMorph('billable', [Project::class, Retainer::class], fn (Builder $query) => $query->where('client_id', $clientId)));
    }

    /** @param Builder<Receivable> $query */
    public function scopeOpen(Builder $query): void
    {
        $credit = CreditApplication::withoutGlobalScope(AccountScope::class)->effectiveAt(today()->toDateString())
            ->whereColumn('credit_applications.receivable_id', 'receivables.id')
            ->whereColumn('credit_applications.account_id', 'receivables.account_id')
            ->selectRaw('coalesce(sum(credit_applications.amount), 0)')
            ->toBase();
        $withholding = WithholdingReview::settlementsOn(today()->toDateString())
            ->whereColumn('withholding_records.receivable_id', 'receivables.id')
            ->whereColumn('withholding_records.account_id', 'receivables.account_id')
            ->selectRaw('coalesce(sum(withholding_records.retained_amount), 0)');

        $query->whereRaw(
            'receivables.amount > (select coalesce(sum(payment_allocations.amount), 0) from payment_allocations where payment_allocations.receivable_id = receivables.id and payment_allocations.account_id = receivables.account_id) + ('.$credit->toSql().') + ('.$withholding->toSql().')',
            [...$credit->getBindings(), ...$withholding->getBindings()],
        );
    }

    /** @param Builder<Receivable> $query */
    public function scopeExcludingCancelled(Builder $query): void
    {
        $query->whereNot(fn (Builder $query) => $query
            ->whereHasMorph('billable', [Project::class], fn (Builder $query) => $query->where('status', ProjectStatus::Cancelled)));
    }

    protected function received(): Attribute
    {
        return Attribute::get(fn (): int => (int) $this->payments->sum('pivot.amount'));
    }

    protected function outstanding(): Attribute
    {
        return Attribute::get(fn (): int => $this->balanceAfter($this->received, today()->toDateString()));
    }

    /** Closing balance for the given date. Both credits and allocations use their effective dates. */
    public function outstandingAt(CarbonInterface|string $date): int
    {
        $day = $date instanceof CarbonInterface ? $date->toDateString() : $date;
        $allocated = PaymentAllocation::query()->where('receivable_id', $this->getKey())
            ->whereDate('allocated_on', '<=', $day)->sum('amount');

        return $this->balanceAfter((int) $allocated, $day);
    }

    private function balanceAfter(int $cash, string $day): int
    {
        $withholding = (int) WithholdingReview::settlementsOn($day)->where('withholding_records.receivable_id', $this->getKey())
            ->sum('withholding_records.retained_amount');

        return max(0, $this->amount - $cash - $this->creditAppliedAt($day) - $withholding);
    }

    private function creditAppliedAt(string $day): int
    {
        return (int) $this->creditApplications()->effectiveAt($day)->sum('amount');
    }

    protected function status(): Attribute
    {
        return Attribute::get(function (): ReceivableStatus {
            if ($this->outstanding <= 0) {
                return ReceivableStatus::Paid;
            }

            if ($this->due_on?->isBefore(today())) {
                return ReceivableStatus::Overdue;
            }

            return $this->received > 0 ? ReceivableStatus::Partial : ReceivableStatus::Open;
        });
    }

    /** "PRINTEE OC-202603-3 · Prestação 1/3" or "MINFRA · Dez 2026". */
    protected function label(): Attribute
    {
        return Attribute::get(fn (): string => trim(($this->billable?->code ?? '').' · '.$this->description, ' ·'));
    }

    protected function client(): Attribute
    {
        return Attribute::get(fn (): ?Client => $this->billable?->client);
    }

    /** The job or retainer page this belongs to; a one-off income has none. */
    public function billableUrl(): ?string
    {
        return match (true) {
            $this->billable instanceof Project => ProjectResource::getUrl('view', ['record' => $this->billable]),
            $this->billable instanceof Retainer => RetainerResource::getUrl('view', ['record' => $this->billable]),
            default => null,
        };
    }
}
