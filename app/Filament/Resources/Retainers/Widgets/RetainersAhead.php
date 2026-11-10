<?php

namespace App\Filament\Resources\Retainers\Widgets;

use App\Models\Retainer;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * Below the "Avenças" list: what the active retainers will generate in each of the next six
 * months, counting from the current one. A retainer bills in the months that fall on its
 * frequency since it started, and stops after its end date.
 */
class RetainersAhead extends Widget
{
    protected string $view = 'filament.retainers.retainers-ahead';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        /** @var Collection<int, Retainer> $retainers */
        $retainers = Retainer::query()->where('is_active', true)->get();
        $first = CarbonImmutable::today()->startOfMonth();

        return [
            'months' => collect(range(0, 5))->map(function (int $offset) use ($first, $retainers): array {
                $month = $first->addMonths($offset);

                return [
                    'label' => ucfirst($month->translatedFormat('M')),
                    'amount' => Money::format((int) $retainers->sum(fn (Retainer $retainer): int => self::billsIn($retainer, $month) ? (int) $retainer->total : 0)),
                ];
            })->all(),
        ];
    }

    private static function billsIn(Retainer $retainer, CarbonImmutable $month): bool
    {
        $start = CarbonImmutable::parse($retainer->starts_on)->startOfMonth();

        if ($month->lt($start) || ($retainer->ends_on !== null && $month->gt($retainer->ends_on))) {
            return false;
        }

        return ((int) $start->diffInMonths($month)) % $retainer->frequency->months() === 0;
    }
}
