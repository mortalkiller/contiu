<?php

namespace App\Models;

use App\Enums\ReceivableStatus;
use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class Client extends Model implements AuditableContract
{
    use Auditable, BelongsToAccount, HasFactory;

    protected $guarded = ['id'];

    /** @var Collection<int, Receivable>|null */
    protected ?Collection $receivablesCache = null;

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** @return HasMany<ClientMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(ClientMessage::class);
    }

    public function lastPaymentReminderAt(): ?Carbon
    {
        $sentAt = $this->messages()->where('type', ClientMessage::PAYMENT_REMINDER)->max('sent_at');

        return $sentAt ? Carbon::parse($sentAt) : null;
    }

    /**
     * Open amounts of the client's projects and retainers, oldest due first (cancelled projects excluded).
     *
     * @return Collection<int, Receivable>
     */
    public function openReceivables(): Collection
    {
        return Receivable::query()
            ->forClient($this->getKey())
            ->excludingCancelled()
            ->open()
            ->with(['payments', 'billable'])
            ->orderBy('due_on')
            ->get();
    }

    /** @return HasMany<Retainer, $this> */
    public function retainers(): HasMany
    {
        return $this->hasMany(Retainer::class);
    }

    /**
     * Every receivable of the client's projects and retainers, cancelled projects excluded.
     *
     * @return Collection<int, Receivable>
     */
    public function allReceivables(): Collection
    {
        return $this->receivablesCache ??= Receivable::query()
            ->forClient($this->getKey())
            ->excludingCancelled()
            ->with(['payments', 'billable'])
            ->orderBy('due_on')
            ->get();
    }

    protected function contractedAmount(): Attribute
    {
        return Attribute::get(fn (): int => (int) $this->allReceivables()->sum('amount'));
    }

    protected function receivedAmount(): Attribute
    {
        return Attribute::get(fn (): int => (int) $this->allReceivables()->sum(fn (Receivable $receivable) => $receivable->received));
    }

    protected function outstandingAmount(): Attribute
    {
        return Attribute::get(fn (): int => (int) $this->allReceivables()->sum(fn (Receivable $receivable) => $receivable->outstanding));
    }

    protected function overdueAmount(): Attribute
    {
        return Attribute::get(fn (): int => (int) $this->allReceivables()
            ->filter(fn (Receivable $receivable) => $receivable->status === ReceivableStatus::Overdue)
            ->sum(fn (Receivable $receivable) => $receivable->outstanding));
    }
}
