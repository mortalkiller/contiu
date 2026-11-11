<?php

namespace App\Models;

use App\Enums\IntegrationCategory;
use App\Models\Concerns\BelongsToAccount;
use Database\Factories\IntegrationConnectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The account's link to an outside service. Which service it is stays a value (`provider`), so a
 * new one needs no new table or column. The credentials are encrypted and never serialised.
 */
class IntegrationConnection extends Model
{
    use BelongsToAccount;

    /** @use HasFactory<IntegrationConnectionFactory> */
    use HasFactory;

    protected $fillable = ['category', 'provider', 'credentials', 'settings', 'connected_by', 'last_synced_at', 'last_error'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'category' => IntegrationCategory::class,
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'last_synced_at' => 'datetime',
            'account_id' => 'integer',
        ];
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    /** @return HasMany<IntegrationRecord, $this> */
    public function records(): HasMany
    {
        return $this->hasMany(IntegrationRecord::class);
    }

    /** @return HasMany<IntegrationOperation, $this> */
    public function operations(): HasMany
    {
        return $this->hasMany(IntegrationOperation::class);
    }
}
