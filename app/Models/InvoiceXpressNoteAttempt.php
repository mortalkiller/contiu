<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;

class InvoiceXpressNoteAttempt extends Model
{
    use BelongsToAccount;

    public const Pending = 'pending';

    public const Draft = 'draft';

    public const Blocked = 'blocked';

    public const Finalized = 'finalized';

    public const array Recoverable = [self::Pending, self::Draft];

    protected $guarded = ['*'];

    protected $hidden = ['revision', 'subdomain', 'proprietary_uid', 'snapshot', 'remote_id'];

    protected function casts(): array
    {
        return [
            'snapshot' => 'encrypted:array',
            'remote_id' => 'integer',
            'original_id' => 'integer',
            'fiscal_document_id' => 'integer',
        ];
    }
}
