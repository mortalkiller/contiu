<?php

namespace App\Filament\Analysis\Widgets;

use App\Models\Payment;
use App\Models\Retainer;
use App\Support\ClientRevenueQuality;
use App\Support\Money;
use App\Support\Period;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * Top of "Qualidade das receitas": how much the biggest client weighs, how much of the money
 * comes from retainers (and so repeats), and how late clients usually pay.
 */
class RevenueQualityStats extends StatsOverviewWidget
{
    public ?int $year = null;

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
            Stat::make('Maior cliente', $share === null ? '—' : number_format($share, 0, ',', ' ').'%')
                ->description($share === null ? 'Sem recebimentos no ano' : $biggest['label'].' · acima de 50% é dependência')
                ->color($share !== null && $share >= 50 ? 'warning' : null)
                ->icon(Phosphor::UserFocus),
            Stat::make('Receita que se repete', $recurringShare === null ? '—' : $recurringShare.'%')
                ->description($recurringShare === null ? 'Sem recebimentos no ano' : Money::format($recurring).' de avenças em '.Money::format($received))
                ->icon(Phosphor::Repeat),
            Stat::make('Atraso habitual', $usualDelay === null ? '—' : trans_choice('{0} Sem atraso|{1} 1 dia|[2,*] :count dias', max(0, $usualDelay)))
                ->description($usualDelay === null ? 'Sem valores liquidados no ano' : 'Depois do vencimento, mediana entre clientes')
                ->icon(Phosphor::Timer),
        ];
    }
}
