<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Database\Factories\FiscalProfileFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** A confirmed version. Corrections are recorded as new rows. */
class FiscalProfile extends Model
{
    use BelongsToAccount;

    /** @use HasFactory<FiscalProfileFactory> */
    use HasFactory;

    public const ESTIMATE_FIELDS = [
        'activity_started_on',
        'vat_exempt',
        'vat_exemption_threshold',
        'ss_contribution_exempt',
    ];

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_date',
            'confirmed_at' => 'immutable_datetime',
            'activity_started_on' => 'immutable_date',
            'has_dependent_employment' => 'boolean',
            'vat_exempt' => 'boolean',
            'vat_exemption_threshold' => 'integer',
            'ss_contribution_exempt' => 'boolean',
        ];
    }

    /** @return list<string> */
    public function confirmedEstimateFields(): array
    {
        return array_keys(array_filter($this->only(self::ESTIMATE_FIELDS), fn (mixed $value): bool => $value !== null));
    }

    public static function effectiveOn(DateTimeInterface $date): ?self
    {
        return static::query()
            ->whereDate('effective_from', '<=', $date->format('Y-m-d'))
            ->inEffectiveOrder()
            ->first();
    }

    /** @param Builder<self> $query */
    #[Scope]
    protected function inEffectiveOrder(Builder $query): void
    {
        $query->orderByDesc('effective_from')->orderByDesc('id');
    }
}
