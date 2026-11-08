<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Enums\ReceivableStatus;
use App\Exceptions\HasPaymentsException;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Retainers\RetainerResource;
use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('paid_on');
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
        $query->whereRaw('amount > (select coalesce(sum(payments.amount), 0) from payments where payments.receivable_id = receivables.id)');
    }

    /** @param Builder<Receivable> $query */
    public function scopeExcludingCancelled(Builder $query): void
    {
        $query->whereNot(fn (Builder $query) => $query
            ->whereHasMorph('billable', [Project::class], fn (Builder $query) => $query->where('status', ProjectStatus::Cancelled)));
    }

    protected function received(): Attribute
    {
        return Attribute::get(fn (): int => (int) $this->payments->sum('amount'));
    }

    protected function outstanding(): Attribute
    {
        return Attribute::get(fn (): int => max(0, (int) $this->amount - $this->received));
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
