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
            ->heading(__('Month by month'))
            ->records(fn (): array => $this->rows())
            ->paginated(false)
            ->recordClasses(fn (array $record) => $record['is_total'] ? 'bg-gray-50 dark:bg-white/5' : null)
            ->columns([
                TextColumn::make('label')
                    ->label(__('Month'))
                    ->weight(fn (array $record) => $record['is_total'] ? 'bold' : 'medium'),
                TextColumn::make('inflows')
                    ->label(__('Inflows'))
                    ->money('EUR', divideBy: 100)
                    ->alignEnd()
                    ->description(fn (array $record) => $record['inNote'])
                    ->wrap(),
                TextColumn::make('outflows')
                    ->label(__('Outflows'))
                    ->money('EUR', divideBy: 100)
                    ->alignEnd()
                    ->description(fn (array $record) => $record['outNote'])
                    ->wrap(),
                TextColumn::make('net')
                    ->label(__('Monthly balance'))
                    ->money('EUR', divideBy: 100)
                    ->alignEnd()
                    ->color(fn (float $state) => $state < 0 ? 'danger' : null),
                TextColumn::make('balance')
                    ->label(__('Running balance'))
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
                $row['overdueInflows'] > 0 ? __('includes :amount overdue', ['amount' => Money::format($row['overdueInflows'], locale: app()->getLocale())]) : null,
                $row['projectedInflows'] > 0 ? __(':amount from retainers yet to be generated', ['amount' => Money::format($row['projectedInflows'], locale: app()->getLocale())]) : null,
            ]);
            $out = array_filter([
                $row['overdueOutflows'] > 0 ? __('includes :amount overdue', ['amount' => Money::format($row['overdueOutflows'], locale: app()->getLocale())]) : null,
                $row['projectedOutflows'] > 0 ? __(':amount from recurring expenses yet to be generated', ['amount' => Money::format($row['projectedOutflows'], locale: app()->getLocale())]) : null,
                $row['socialSecurity'] > 0 ? __('Social Security :amount :status', ['amount' => Money::format($row['socialSecurity'], locale: app()->getLocale()), 'status' => $row['socialSecurityConfirmed'] ? __('confirmed') : __('estimated')]) : null,
                $row['confirmedVat'] > 0 ? __('Confirmed VAT :amount', ['amount' => Money::format($row['confirmedVat'], locale: app()->getLocale())]) : null,
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
            'label' => __('Total'),
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
