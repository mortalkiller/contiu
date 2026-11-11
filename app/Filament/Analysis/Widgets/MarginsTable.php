<?php

namespace App\Filament\Analysis\Widgets;

use App\Filament\Analysis\Concerns\UsesMarginPeriod;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use MortalKiller\FilamentAppearance\Tables\Columns\ProgressColumn;

/**
 * Shared table for the margin breakdowns (business line / client), with a total row.
 */
abstract class MarginsTable extends TableWidget
{
    use UsesMarginPeriod;

    protected int|string|array $columnSpan = 'full';

    abstract protected function heading(): string;

    abstract protected function groupLabel(): string;

    /** @return array<string, array<string, mixed>> */
    abstract protected function groups(): array;

    public function table(Table $table): Table
    {
        return $table
            ->heading(fn () => $this->heading())
            ->records(fn (): array => $this->rows())
            ->paginated(false)
            ->recordClasses(fn (array $record) => $record['is_total'] ? 'bg-gray-50 dark:bg-white/5' : null)
            ->columns([
                // Two tables side by side, as in the mock: a long client name wraps instead of widening the table.
                TextColumn::make('label')
                    ->label($this->groupLabel())
                    ->wrap()
                    ->weight(fn (array $record) => $record['is_total'] ? 'bold' : 'medium'),
                TextColumn::make('invoiced')->label(__('Invoiced'))->money('EUR', divideBy: 100)->alignEnd(),
                TextColumn::make('directCosts')->label(__('Direct costs'))->money('EUR', divideBy: 100)->alignEnd(),
                TextColumn::make('margin')
                    ->label(__('Margin'))
                    ->money('EUR', divideBy: 100)
                    ->alignEnd()
                    ->weight('semibold')
                    ->color(fn (float $state) => $state < 0 ? 'danger' : null),
                // A bar with the number beside it: red below zero, amber below 30%.
                ProgressColumn::make('marginPercent')
                    ->label(__('Margin %'))
                    ->color(fn (?float $state): string => match (true) {
                        $state < 0 => 'danger',
                        $state < 30 => 'warning',
                        default => 'primary',
                    }),
            ])
            ->emptyStateHeading(__('No transactions in this period'));
    }

    /** @return array<string, array<string, mixed>> */
    private function rows(): array
    {
        $rows = array_map(fn (array $row) => [...$row, 'is_total' => false], $this->groups());

        if ($rows === []) {
            return [];
        }

        $totals = $this->report()->totals();

        $rows['__total'] = [
            'label' => __('Total'),
            'invoiced' => $totals['invoiced'],
            'received' => $totals['received'],
            'directCosts' => $totals['directCosts'],
            'margin' => $totals['margin'],
            'marginPercent' => $totals['marginPercent'],
            'is_total' => true,
        ];

        return $rows;
    }
}
