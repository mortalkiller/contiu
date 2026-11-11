<?php

namespace App\Models;

use App\Enums\PortugueseVatRegion;
use App\Exceptions\AccountIsReadOnly;
use App\Support\CurrentAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class ClientAddress extends Model
{
    protected $fillable = [
        'country_code',
        'street',
        'postal_code',
        'locality',
        'district_code',
        'municipality_code',
        'parish_code',
        'district',
        'parish',
    ];

    protected function casts(): array
    {
        return [
            'district_code' => 'string',
            'municipality_code' => 'string',
            'parish_code' => 'string',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('account', fn (Builder $query) => $query->whereHas('client'));

        static::saving(function (self $address): void {
            $address->authorizeWrite();
            $address->normalizeAdministrativeAreas();
        });

        static::deleting(fn (self $address) => $address->authorizeWrite());
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return Builder<PortugueseAdministrativeArea> */
    public static function administrativeAreas(string $kind, ?string $parentCode = null): Builder
    {
        return PortugueseAdministrativeArea::query()->where('kind', $kind)->where('parent_code', $parentCode);
    }

    public function vatRegion(): ?PortugueseVatRegion
    {
        if ($this->country_code !== 'PT' || $this->district_code === null) {
            return null;
        }

        return self::administrativeAreas('district')->find($this->district_code)?->vat_region;
    }

    private function authorizeWrite(): void
    {
        if (CurrentAccount::readOnly()) {
            throw new AccountIsReadOnly;
        }

        Client::query()->findOrFail($this->client_id);

        if ($this->exists && $this->isDirty('client_id')) {
            Client::query()->findOrFail($this->getRawOriginal('client_id'));
        }
    }

    private function normalizeAdministrativeAreas(): void
    {
        if ($this->country_code !== 'PT') {
            $this->district_code = $this->municipality_code = $this->parish_code = null;

            return;
        }

        $this->district = $this->parish = null;

        foreach ([
            'district' => null,
            'municipality' => $this->district_code,
            'parish' => $this->municipality_code,
        ] as $kind => $parentCode) {
            $field = $kind.'_code';

            if ($this->{$field} !== null && ! self::administrativeAreas($kind, $parentCode)->whereKey($this->{$field})->exists()) {
                throw ValidationException::withMessages([$field => __('validation.exists', ['attribute' => $kind])]);
            }
        }
    }
}
