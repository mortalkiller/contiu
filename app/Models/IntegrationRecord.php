<?php

namespace App\Models;

use App\Enums\IntegrationRecordState;
use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * What a provider holds for one record: its id there, how it compares with ours and a short copy
 * of what was last read. With no local record it is something that only exists at the provider.
 */
class IntegrationRecord extends Model
{
    use BelongsToAccount;

    protected $fillable = ['integration_connection_id', 'remote_type', 'remote_id', 'state', 'remote', 'remote_hash', 'synced_at'];

    protected function casts(): array
    {
        return [
            'state' => IntegrationRecordState::class,
            'remote' => 'array',
            'synced_at' => 'datetime',
            'account_id' => 'integer',
        ];
    }

    /** @return BelongsTo<IntegrationConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }

    /** @return MorphTo<Model, $this> */
    public function record(): MorphTo
    {
        return $this->morphTo();
    }
}
