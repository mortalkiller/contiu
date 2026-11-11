<?php

namespace App\Filament\Analysis\Widgets;

use App\Models\Payment;
use App\Models\Retainer;
use App\Support\ClientRevenueQuality;
use App\Support\Money;
use App\Support\Period;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;
use Livewire\Attributes\Reactive;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * Top of "Qualidade das receitas": how much the biggest client weighs, how much of the money
 * comes from retainers (and so repeats), and how late clients usually pay.
 */
class RevenueQualityStats extends StatsOverviewWidget
{
    #[Reactive]
    public ?int $year = null;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int|array
    {
        return ['md' => 3];
    }

    protected function getStats(): array
    {
        $period = Period::of($this->year ?? today()->year);
        $rows = collect((new ClientRevenueQuality($period))->rows());
        $biggest = $rows->first();
        $share = $biggest['share'] ?? null;

        $allocations = Payment::query()
            ->with('allocations.receivable')
            ->whereBetween('paid_on', $period->range())
            ->get()
            ->flatMap->allocations;
        $received = (int) $allocations->sum('amount');
        $recurring = (int) $allocations
            ->filter(fn ($allocation): bool => $allocation->receivable?->billable_type === (new Retainer)->getMorphClass())
            ->sum('amount');
        $recurringShare = $received > 0 ? (int) round($recurring / $received * 100) : null;

        $delays = $rows->pluck('medianDelay')->reject(fn ($days): bool => $days === null);
        $usualDelay = $delays->isEmpty() ? null : (int) round($delays->median());

        return [
            Stat::make(__('Largest client'), $share === null ? '—' : Number::format($share, precision: 0, locale: app()->getLocale()).'%')
                ->description($share === null ? __('No payments received this year') : __(':client · over 50% means dependency', ['client' => $biggest['label']]))
                ->color($share !== null && $share >= 50 ? 'warning' : null)
                ->icon(Phosphor::UserFocus),
            Stat::make(__('Recurring revenue'), $recurringShare === null ? '—' : $recurringShare.'%')
                ->description($recurringShare === null ? __('No payments received this year') : __(':recurring from retainers out of :received', ['recurring' => Money::format($recurring, locale: app()->getLocale()), 'received' => Money::format($received, locale: app()->getLocale())]))
                ->icon(Phosphor::Repeat),
            Stat::make(__('Typical delay'), $usualDelay === null ? '—' : trans_choice('{0} No delay|{1} 1 day|[2,*] :count days', max(0, $usualDelay)))
                ->description($usualDelay === null ? __('No amounts settled this year') : __('Median across clients after the due date'))
                ->icon(Phosphor::Timer),
        ];
    }
}
