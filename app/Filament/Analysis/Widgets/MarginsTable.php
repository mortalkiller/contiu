<?php

namespace App\Filament\Analysis\Widgets;

use App\Filament\Analysis\Concerns\UsesMarginPeriod;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

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
            ->heading(fn () => $this->heading().' · '.$this->period()->label())
            ->records(fn (): array => $this->rows())
            ->paginated(false)
            ->recordClasses(fn (array $record) => $record['is_total'] ? 'bg-gray-50 dark:bg-white/5' : null)
            ->columns([
                TextColumn::make('label')
                    ->label($this->groupLabel())
                    ->weight(fn (array $record) => $record['is_total'] ? 'bold' : 'medium'),
                TextColumn::make('invoiced')->label('Faturado')->money('EUR')->alignEnd(),
                TextColumn::make('received')->label('Recebido')->money('EUR')->alignEnd(),
                TextColumn::make('directCosts')->label('Custos diretos')->money('EUR')->alignEnd(),
                TextColumn::make('margin')
                    ->label('Margem')
                    ->money('EUR')
                    ->alignEnd()
                    ->weight('semibold')
                    ->color(fn (float $state) => $state < 0 ? 'danger' : null),
                TextColumn::make('marginPercent')
                    ->label('Margem %')
                    ->alignEnd()
                    ->formatStateUsing(fn (?float $state) => $state === null ? '—' : number_format($state, 1, ',', ' ').'%')
                    ->placeholder('—')
                    ->color(fn (?float $state) => match (true) {
                        $state === null => 'gray',
                        $state < 0 => 'danger',
                        $state < 30 => 'warning',
                        default => 'success',
                    }),
            ])
            ->emptyStateHeading('Sem movimentos neste período');
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
            'label' => 'Total',
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
