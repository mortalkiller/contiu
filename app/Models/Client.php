<?php

namespace App\Models;

use App\Enums\FiscalProfilePartyType;
use App\Enums\FiscalProfileViesStatus;
use App\Enums\Language;
use App\Enums\Seat;
use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Builder;
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

    protected $attributes = ['vat_validation_status' => 'pending', 'language' => 'pt-PT'];

    public function preferredLanguage(): Language
    {
        return Language::resolve($this->language);
    }

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
     * Every amount charged to the client (projects, retainers and direct sales), cancelled
     * projects excluded. A query, not a relation: it feeds the "A receber" tab of the client page.
     *
     * @return Builder<Receivable>
     */
    public function receivablesQuery(): Builder
    {
        return Receivable::query()->forClient($this->getKey())->excludingCancelled();
    }

    /**
     * What the client paid: payments settling its amounts, and advances not allocated yet.
     *
     * @return Builder<Payment>
     */
    public function paymentsQuery(): Builder
    {
        return Payment::query()->where(fn (Builder $query) => $query
            ->where('advance_client_id', $this->getKey())
            ->orWhereHas('allocations.receivable', fn (Builder $query) => $query->forClient($this->getKey())));
    }

    /**
     * What was spent on the client's projects and retainers.
     *
     * @return Builder<Expense>
     */
    public function expensesQuery(): Builder
    {
        return Expense::query()->where(fn (Builder $query) => $query
            ->whereHas('project', fn (Builder $query) => $query->where('client_id', $this->getKey()))
            ->orWhereHas('retainer', fn (Builder $query) => $query->where('client_id', $this->getKey())));
    }

    /**
     * Every receivable of the client's projects and retainers, cancelled projects excluded.
     *
     * @return Collection<int, Receivable>
     */
    public function allReceivables(): Collection
    {
        return $this->receivablesCache ??= self::receivablesForClients([$this->getKey()]);
    }

    /** @param Builder<Client> $query */
    public function scopeWithReceivableTotals(Builder $query): void
    {
        $query->afterQuery(function (mixed $clients): void {
            if (! $clients instanceof Collection || $clients->isEmpty()) {
                return;
            }

            $byClient = self::receivablesForClients($clients->modelKeys())
                ->groupBy(fn (Receivable $receivable): int => (int) $receivable->billable?->client_id);

            foreach ($clients as $client) {
                $client->receivablesCache = $byClient->get($client->getKey()) ?? new Collection;
            }
        });
    }

    /**
     * @param  list<int>  $clientIds
     * @return Collection<int, Receivable>
     */
    private static function receivablesForClients(array $clientIds): Collection
    {
        return Receivable::query()
            ->forClients($clientIds)
            ->excludingCancelled()
            ->withOutstandingBalance()
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
        return Attribute::get(fn (): int => (int) $this->allReceivables()->sum(fn (Receivable $receivable) => $receivable->projectedOutstanding()));
    }

    protected function overdueAmount(): Attribute
    {
        return Attribute::get(fn (): int => (int) $this->allReceivables()
            ->filter(fn (Receivable $receivable) => $receivable->due_on?->isBefore(today()))
            ->sum(fn (Receivable $receivable) => $receivable->projectedOutstanding()));
    }
}
