<?php

namespace App\Models;

use App\Enums\IntegrationOperationStatus;
use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Something asked of a provider and how it went. Rows with a `key` also make the request safe to
 * repeat: the second attempt finds the first instead of doing the work again.
 */
class IntegrationOperation extends Model
{
    use BelongsToAccount;

    protected $fillable = ['integration_connection_id', 'user_id', 'kind', 'status', 'key', 'summary', 'detail'];

    protected function casts(): array
    {
        return [
            'status' => IntegrationOperationStatus::class,
            'account_id' => 'integer',
        ];
    }

    /** @return BelongsTo<IntegrationConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
