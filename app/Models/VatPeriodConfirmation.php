<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;

class VatPeriodConfirmation extends Model
{
    use BelongsToAccount;

    protected $fillable = ['starts_on', 'months', 'liquidated', 'deductible', 'adjustments', 'due_on', 'paid_on', 'reference', 'source_hash'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'months' => 'integer',
            'liquidated' => 'integer',
            'deductible' => 'integer',
            'adjustments' => 'integer',
            'due_on' => 'immutable_date',
            'paid_on' => 'immutable_date',
        ];
    }

    public function balance(): int
    {
        return $this->liquidated - $this->deductible + $this->adjustments;
    }
}
