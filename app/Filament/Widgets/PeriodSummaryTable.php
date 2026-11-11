<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesDashboardPeriod;
use App\Support\Change;
use App\Support\PeriodSummary;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

/**
 * The Excel "Resumo" block for the selected period, next to the previous period.
 */
class PeriodSummaryTable extends TableWidget
{
    use HasSkeletonPlaceholder;
    use UsesDashboardPeriod;

    protected static ?int $sort = 7;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Period summary'))
            ->description(fn () => __(':period compared with :previous.', ['period' => $this->period()->label(), 'previous' => $this->period()->previous()->label()]))
            ->records(fn (): array => $this->rows())
            ->paginated(false)
            ->recordClasses(fn (array $record) => $record['highlight'] ? 'bg-warning-50 dark:bg-warning-400/10' : null)
            ->columns([
                TextColumn::make('label')
                    ->label('')
                    ->weight('medium')
                    ->description(fn (array $record) => $record['note'] ?? null)
                    ->wrap(),
                TextColumn::make('current')
                    ->label(fn () => $this->period()->label())
                    ->money('EUR', divideBy: 100)
                    ->weight('semibold')
                    ->alignEnd()
                    ->color(fn (float $state) => $state < 0 ? 'danger' : null),
                TextColumn::make('previous')
                    ->label(fn () => $this->period()->previous()->label())
                    ->money('EUR', divideBy: 100)
                    ->color('gray')
                    ->alignEnd()
                    ->placeholder('—'),
                TextColumn::make('change')
                    ->label(__('Change'))
                    ->alignEnd()
                    ->size('xs')
                    ->placeholder('')
                    ->color(fn (array $record) => $record['changeColor']),
            ]);
    }

    /** @return array<string, array<string, mixed>> */
    private function rows(): array
    {
        $current = $this->summary();
        $previous = $current->previous();

        $row = function (string $label, string $figure, bool $higherIsBetter = true, bool $highlight = false) use ($current, $previous): array {
            $change = $current->change($figure);

            return [
                'label' => $label,
                'current' => $current->{$figure}(),
                'previous' => $previous->{$figure}(),
                'change' => $change->percent === null ? 'novo' : $change->label(),
                'changeColor' => $this->changeColor($change, $higherIsBetter),
                'highlight' => $highlight,
            ];
        };

        return [
            'contracted' => $row(__('Total contracted'), 'contracted'),
            'received' => $row(__('Total received'), 'received'),
            'outstanding' => $row(__('Total receivable'), 'outstanding', higherIsBetter: false, highlight: true),
            'overdue' => $row(__('Outstanding overdue'), 'overdue', higherIsBetter: false),
            'next30' => [
                'label' => __('Due in the next 30 days'),
                'note' => __('starting today'),
                'current' => PeriodSummary::dueNext30Days(),
                'previous' => null,
                'change' => null,
                'changeColor' => 'gray',
                'highlight' => false,
            ],
            'paidExpenses' => $row(__('Expenses paid'), 'paidExpenses', higherIsBetter: false),
            'unpaidExpenses' => $row(__('Unpaid expenses'), 'unpaidExpenses', higherIsBetter: false),
            'cashResult' => $row(__('Cash result'), 'cashResult'),
            'forecastResult' => $row(__('Expected result'), 'forecastResult'),
        ];
    }

    private function changeColor(Change $change, bool $higherIsBetter): string
    {
        return match (true) {
            $change->percent === null, $change->isFlat() => 'gray',
            $change->isUp() === $higherIsBetter => 'success',
            default => 'danger',
        };
    }
}
