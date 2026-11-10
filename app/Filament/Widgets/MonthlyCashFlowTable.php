<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesDashboardPeriod;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

/**
 * The Excel "Mês · Recebido · Despesas pagas · Cash flow" table: months of the selected year, or
 * the days with movement when a month is selected, with a total row at the end.
 */
class MonthlyCashFlowTable extends TableWidget
{
    use HasSkeletonPlaceholder;
    use UsesDashboardPeriod;

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(fn () => ($this->period()->isMonth() ? 'Cash flow diário · ' : 'Cash flow mensal · ').$this->period()->label())
            ->records(fn (): array => $this->rows())
            ->paginated(false)
            ->recordClasses(fn (array $record) => match (true) {
                $record['is_total'] => 'bg-gray-50 dark:bg-white/5',
                $record['is_current'] => 'bg-primary-50/60 dark:bg-primary-400/10',
                default => null,
            })
            ->columns([
                TextColumn::make('label')
                    ->label(fn () => $this->period()->isMonth() ? 'Dia' : 'Mês')
                    ->weight(fn (array $record) => $record['is_total'] ? 'bold' : null),
                TextColumn::make('received')
                    ->label('Recebido')
                    ->money('EUR', divideBy: 100)
                    ->alignEnd()
                    ->weight(fn (array $record) => $record['is_total'] ? 'bold' : null),
                TextColumn::make('paid')
                    ->label('Despesas pagas')
                    ->money('EUR', divideBy: 100)
                    ->alignEnd()
                    ->weight(fn (array $record) => $record['is_total'] ? 'bold' : null),
                TextColumn::make('refunded')->label('Reembolsos a clientes')
                    ->money('EUR', divideBy: 100)->alignEnd()
                    ->visible(fn (): bool => $this->summary()->refunded() > 0),
                TextColumn::make('result')
                    ->label('Cash flow')
                    ->money('EUR', divideBy: 100)
                    ->alignEnd()
                    ->weight(fn (array $record) => $record['is_total'] ? 'bold' : 'medium')
                    ->color(fn (float $state) => $state < 0 ? 'danger' : null),
            ])
            ->emptyStateHeading('Sem movimentos neste período');
    }

    /** @return array<string, array<string, mixed>> */
    private function rows(): array
    {
        $period = $this->period();
        $flow = $this->summary()->cashFlow();
        $currentIndex = ! $period->isMonth() && $period->year === today()->year ? today()->month - 1 : null;

        $rows = [];

        foreach ($flow as $index => $bucket) {
            if ($period->isMonth() && $bucket['received'] == 0 && $bucket['paid'] == 0 && $bucket['refunded'] == 0) {
                continue;
            }

            $rows['p'.$index] = [...$bucket, 'is_total' => false, 'is_current' => $index === $currentIndex];
        }

        if ($rows === []) {
            return [];
        }

        $rows['total'] = [
            'label' => 'Total',
            'received' => round(array_sum(array_column($flow, 'received')), 2),
            'paid' => round(array_sum(array_column($flow, 'paid')), 2),
            'refunded' => array_sum(array_column($flow, 'refunded')),
            'result' => round(array_sum(array_column($flow, 'result')), 2),
            'is_total' => true,
            'is_current' => false,
        ];

        return $rows;
    }
}
