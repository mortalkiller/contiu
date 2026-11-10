<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;

class TerritorialTurnoverCoverage extends Model
{
    use BelongsToAccount;

    protected $fillable = ['year', 'through_on', 'source_revision', 'confirmed_by'];

    protected function casts(): array
    {
        return ['year' => 'integer', 'through_on' => 'immutable_date'];
    }
}
