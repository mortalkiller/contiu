<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Key/value application settings (see App\Support\FinanceSettings for the typed accessors).
 */
class Setting extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}
