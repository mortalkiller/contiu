<?php

namespace App\Filament\Resources\Retainers\Widgets;

use App\Models\Retainer;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Collection;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * Above the "Avenças" list: what the active retainers bring in per month and per year, the next
 * amount to fall due, and what is still open.
 */
class RetainersSummary extends StatsOverviewWidget
{
    use HasSkeletonPlaceholder;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int|array
    {
        return ['md' => 2, 'xl' => 4];
    }

    protected function getStats(): array
    {
        /** @var Collection<int, Retainer> $active */
        $active = Retainer::query()->where('is_active', true)->with(['client', 'receivables.payments'])->get();

        $perYear = (int) $active->sum(fn (Retainer $retainer): int => $retainer->yearly_value);
        $outstanding = (int) $active->sum(fn (Retainer $retainer): int => $retainer->outstanding_amount);
        $overdue = (int) $active->sum(fn (Retainer $retainer): int => $retainer->overdue_amount);

        $next = $active
            ->filter(fn (Retainer $retainer): bool => $retainer->next_due_on !== null)
            ->sortBy(fn (Retainer $retainer) => $retainer->next_due_on)
            ->first();

        return [
            Stat::make('Receita recorrente por mês', Money::format(Money::round($perYear / 12)))
                ->description(trans_choice('{0} Nenhuma avença ativa|{1} 1 avença ativa|[2,*] :count avenças ativas', $active->count()))
                ->icon(Phosphor::Repeat),
            Stat::make('Por ano', Money::format($perYear))
                ->description('Se nada mudar')
                ->icon(Phosphor::CalendarBlank),
            Stat::make('Próximo vencimento', $next === null ? '—' : $next->next_due_on->format('d/m/Y'))
                ->description($next === null ? 'Nada por vencer' : $next->client->name)
                ->icon(Phosphor::Clock),
            Stat::make('Em aberto', Money::format($outstanding))
                ->description(match (true) {
                    $outstanding === 0 => 'Tudo recebido',
                    $overdue > 0 => Money::format($overdue).' vencidos',
                    default => 'Nada vencido',
                })
                ->color(match (true) {
                    $outstanding === 0 => 'success',
                    $overdue > 0 => 'danger',
                    default => 'warning',
                })
                ->icon(Phosphor::HourglassMedium),
        ];
    }
}
