<?php

namespace App\Filament\Analysis\Widgets;

use App\Filament\Analysis\Concerns\ForecastsMonths;
use App\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

class CashForecastTable extends TableWidget
{
    use ForecastsMonths;
    use HasSkeletonPlaceholder;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Mês a mês')
            ->records(fn (): array => $this->rows())
            ->paginated(false)
            ->recordClasses(fn (array $record) => $record['is_total'] ? 'bg-gray-50 dark:bg-white/5' : null)
            ->columns([
                TextColumn::make('label')
                    ->label('Mês')
                    ->weight(fn (array $record) => $record['is_total'] ? 'bold' : 'medium'),
                TextColumn::make('inflows')
                    ->label('Entradas')
                    ->money('EUR', divideBy: 100)
                    ->alignEnd()
                    ->description(fn (array $record) => $record['inNote'])
                    ->wrap(),
                TextColumn::make('outflows')
                    ->label('Saídas')
                    ->money('EUR', divideBy: 100)
                    ->alignEnd()
                    ->description(fn (array $record) => $record['outNote'])
                    ->wrap(),
                TextColumn::make('net')
                    ->label('Saldo do mês')
                    ->money('EUR', divideBy: 100)
                    ->alignEnd()
                    ->color(fn (float $state) => $state < 0 ? 'danger' : null),
                TextColumn::make('balance')
                    ->label('Saldo acumulado')
                    ->visible(fn (): bool => $this->forecast()->showsBalance())
                    ->money('EUR', divideBy: 100)
                    ->alignEnd()
                    ->weight('semibold')
                    ->placeholder('')
                    ->color(fn (?float $state) => $state !== null && $state < 0 ? 'danger' : null),
            ]);
    }

    /** @return array<string, array<string, mixed>> */
    private function rows(): array
    {
        $forecast = $this->forecast();
        $rows = [];

        foreach ($forecast->months() as $row) {
            $in = array_filter([
                $row['overdueInflows'] > 0 ? 'inclui '.Money::format($row['overdueInflows']).' em atraso' : null,
                $row['projectedInflows'] > 0 ? Money::format($row['projectedInflows']).' de avenças por gerar' : null,
            ]);
            $out = array_filter([
                $row['overdueOutflows'] > 0 ? 'inclui '.Money::format($row['overdueOutflows']).' em atraso' : null,
                $row['projectedOutflows'] > 0 ? Money::format($row['projectedOutflows']).' de recorrentes por gerar' : null,
                $row['socialSecurity'] > 0 ? 'Seg. Social '.Money::format($row['socialSecurity']).($row['socialSecurityConfirmed'] ? ' confirmada' : ' estimada') : null,
                $row['confirmedVat'] > 0 ? 'IVA confirmado '.Money::format($row['confirmedVat']) : null,
            ]);

            $rows[$row['month']->format('Y-m')] = [
                'label' => $row['label'],
                'inflows' => $row['inflows'],
                'outflows' => $row['outflows'],
                'net' => $row['net'],
                'balance' => $row['balance'],
                'inNote' => implode(' · ', $in) ?: null,
                'outNote' => implode(' · ', $out) ?: null,
                'is_total' => false,
            ];
        }

        $totals = $forecast->totals();

        $rows['total'] = [
            'label' => 'Total',
            'inflows' => $totals['inflows'],
            'outflows' => $totals['outflows'],
            'net' => $totals['net'],
            'balance' => null,
            'inNote' => null,
            'outNote' => null,
            'is_total' => true,
        ];

        return $rows;
    }
}
