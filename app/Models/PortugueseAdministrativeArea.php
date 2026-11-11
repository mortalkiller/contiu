<?php

namespace App\Models;

use App\Enums\PortugueseVatRegion;
use Illuminate\Database\Eloquent\Model;

class PortugueseAdministrativeArea extends Model
{
    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['vat_region' => PortugueseVatRegion::class];
    }
}
