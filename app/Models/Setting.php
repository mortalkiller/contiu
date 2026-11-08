<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;

/**
 * Key/value application settings (see App\Support\FinanceSettings for the typed accessors).
 */
class Setting extends Model
{
    use BelongsToAccount;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}
