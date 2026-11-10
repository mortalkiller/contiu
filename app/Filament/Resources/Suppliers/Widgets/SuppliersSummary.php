<?php

namespace App\Filament\Resources\Suppliers\Widgets;

use App\Filament\Support\ProportionBar;
use App\Models\Expense;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Collection;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * Above the "Fornecedores" list: who this year's expenses went to. The three biggest suppliers
 * are named; the rest goes together.
 */
class SuppliersSummary extends StatsOverviewWidget
{
    use HasSkeletonPlaceholder;

    private const array TONES = ['clay', 'strong', 'medium'];

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int|array
    {
        return ['md' => 3];
    }

    protected function getStats(): array
    {
        /** @var Collection<int, Expense> $expenses */
        $expenses = Expense::query()->whereYear('spent_on', today()->year)->with('supplier')->get();

        $bySupplier = $expenses
            ->groupBy(fn (Expense $expense): string => $expense->supplier?->name ?? 'Sem fornecedor')
            ->map(fn (Collection $group): int => (int) $group->sum(fn (Expense $expense): int => (int) $expense->total))
            ->sortDesc();

        $total = (int) $bySupplier->sum();
        $biggest = $bySupplier->keys()->first();

        $segments = [];

        foreach ($bySupplier->take(3)->keys()->values() as $index => $name) {
            $segments[] = ['label' => $name, 'cents' => $bySupplier[$name], 'tone' => self::TONES[$index]];
        }

        if ($bySupplier->count() > 3) {
            $segments[] = ['label' => 'Outros', 'cents' => (int) $bySupplier->skip(3)->sum(), 'tone' => 'soft'];
        }

        return [
            Stat::make('Gasto em '.today()->year, Money::format($total))
                ->description(trans_choice('{0} Nenhum fornecedor com despesas|{1} 1 fornecedor com despesas|[2,*] :count fornecedores com despesas', $bySupplier->count()))
                ->icon(Phosphor::Receipt),
            Stat::make('Maior fornecedor', $biggest ?? '—')
                ->description($biggest === null
                    ? 'Ainda sem despesas este ano'
                    : Money::format($bySupplier[$biggest]).' · '.round($bySupplier[$biggest] / max(1, $total) * 100).'% do total')
                ->icon(Phosphor::Storefront),
            Stat::make('Onde gastas mais', $bySupplier->isEmpty() ? '—' : trans_choice('{1} 1 fornecedor|[2,*] :count fornecedores', $bySupplier->count()))
                ->description($bySupplier->isEmpty() ? 'Ainda sem despesas este ano' : ProportionBar::render($segments))
                ->icon(Phosphor::ChartPieSlice),
        ];
    }
}
