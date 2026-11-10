<?php

namespace App\Models;

use App\Enums\FiscalProfilePartyType;
use App\Enums\FiscalProfileViesStatus;
use App\Enums\ReceivableStatus;
use App\Enums\Seat;
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

    protected $guarded = ['id', 'vat_validation_status', 'vat_validated_at'];

    protected $attributes = ['vat_validation_status' => 'pending'];

    protected function casts(): array
    {
        return [
            'seat' => Seat::class,
            'is_business' => 'boolean',
            'fiscal_party_type' => FiscalProfilePartyType::class,
            'vat_validation_status' => FiscalProfileViesStatus::class,
            'vat_validated_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Client $client): void {
            if ($client->isDirty(['fiscal_country_code', 'fiscal_party_type', 'vat_number'])) {
                $client->vat_validation_status = FiscalProfileViesStatus::Pending;
                $client->vat_validated_at = null;
            }
        });
    }

    protected function fiscalCountryCode(): Attribute
    {
        return Attribute::set(fn (?string $value): ?string => filled($value) ? strtoupper(trim($value)) : null);
    }

    /** @return array{country: string, party: string, vat: string}|null */
    public function viesIdentity(): ?array
    {
        if (blank($this->fiscal_country_code) || $this->fiscal_party_type === null || blank($this->vat_number)) {
            return null;
        }

        return [
            'country' => $this->fiscal_country_code,
            'party' => $this->fiscal_party_type->value,
            'vat' => $this->vat_number,
        ];
    }

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
