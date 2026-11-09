<?php

namespace App\Support;

use App\Enums\Seat;
use App\Models\Expense;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * VAT self-assessed (autoliquidação) on services bought from suppliers seated abroad: the expense
 * carries no VAT, so the buyer owes it at the configured rate. Goods are left out (different rules).
 */
final class ReverseChargeVat
{
    public function __construct(private readonly FinanceSettings $settings) {}

    /**
     * @return array{base: int, vat: int, expenses: Collection<int, Expense>} cents
     */
    public function forMonth(CarbonImmutable $month): array
    {
        $expenses = $this->foreignServices($month)->where('vat_rate', 0)->get();
        $base = (int) $expenses->sum('net_amount');

        return [
            'base' => $base,
            'vat' => Money::share($base, $this->settings->reverseChargeVatRate()),
            'expenses' => $expenses,
        ];
    }

    /**
     * Foreign services that already carry VAT: not self-assessed, worth a second look.
     *
     * @return Collection<int, Expense>
     */
    public function foreignWithVat(CarbonImmutable $month): Collection
    {
        return $this->foreignServices($month)->where('vat_rate', '>', 0)->get();
    }

    /** @return Builder<Expense> */
    private function foreignServices(CarbonImmutable $month): Builder
    {
        return Expense::query()
            ->with('supplier')
            ->where('is_goods', false)
            ->whereBetween('spent_on', [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()])
            ->whereHas('supplier', fn (Builder $query) => $query->whereIn('seat', [Seat::EuropeanUnion->value, Seat::OutsideEu->value]));
    }
}
