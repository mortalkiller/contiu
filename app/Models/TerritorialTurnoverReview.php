<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;

class TerritorialTurnoverReview extends Model
{
    use BelongsToAccount;

    protected $fillable = [
        'fiscal_document_id', 'operation_on', 'eligible_cents', 'source_revision', 'review_revision', 'confirmed_by',
    ];

    protected function casts(): array
    {
        return ['operation_on' => 'immutable_date', 'eligible_cents' => 'integer'];
    }
}
