<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;

/** A local revision of the monthly totals the user says they declared. */
class SocialSecurityDeclaration extends Model
{
    use BelongsToAccount;

    public $timestamps = false;

    protected $fillable = ['quarter', 'amounts', 'recorded_at', 'user_id'];

    protected function casts(): array
    {
        return [
            'amounts' => 'array',
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
