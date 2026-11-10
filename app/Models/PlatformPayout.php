<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformPayout extends Model
{
    use BelongsToAccount;

    protected $guarded = ['id', 'account_id'];

    protected function casts(): array
    {
        return [
            'original_gross' => 'integer',
            'original_commission' => 'integer',
            'gross_eur' => 'integer',
            'commission_eur' => 'integer',
            'undone_at' => 'datetime',
        ];
    }

    /** @param Builder<PlatformPayout> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('undone_at')->whereNotNull('one_off_income_id');
    }

    /** @return BelongsTo<OneOffIncome, $this> */
    public function income(): BelongsTo
    {
        return $this->belongsTo(OneOffIncome::class, 'one_off_income_id');
    }

    /** @return BelongsTo<FiscalDocument, $this> */
    public function commissionDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class);
    }
}
