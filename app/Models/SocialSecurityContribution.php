<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;

/** A locally confirmed amount due for a contribution month. */
class SocialSecurityContribution extends Model
{
    use BelongsToAccount;

    public $timestamps = false;

    protected $fillable = ['contribution_month', 'amount', 'confirmed_at', 'user_id'];

    protected function casts(): array
    {
        return [
            'contribution_month' => 'immutable_date',
            'amount' => 'integer',
            'confirmed_at' => 'immutable_datetime',
        ];
    }
}
