<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;

class InvoiceXpressIssueAttempt extends Model
{
    use BelongsToAccount;

    public const Pending = 'pending';

    public const Draft = 'draft';

    public const Blocked = 'blocked';

    public const Finalized = 'finalized';

    public const array Recoverable = [self::Pending, self::Draft];

    protected $hidden = ['snapshot', 'provider_target', 'proprietary_uid', 'remote_draft_id'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'snapshot' => 'encrypted:array',
            'remote_draft_id' => 'integer',
            'provider_invoice_id' => 'integer',
            'finalized_at' => 'immutable_datetime',
        ];
    }
}
