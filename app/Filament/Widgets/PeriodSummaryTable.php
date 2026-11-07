<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesDashboardPeriod;
use App\Support\Change;
use App\Support\PeriodSummary;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * The Excel "Resumo" block for the selected period, next to the previous period.
 */
class PeriodSummaryTable extends TableWidget
{
    use UsesDashboardPeriod;

    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Resumo do período')
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
                    ->money('EUR')
                    ->weight('semibold')
                    ->alignEnd()
                    ->color(fn (float $state) => $state < 0 ? 'danger' : null),
                TextColumn::make('previous')
                    ->label(fn () => $this->period()->previous()->label())
                    ->money('EUR')
                    ->color('gray')
                    ->alignEnd()
                    ->placeholder('—'),
                TextColumn::make('change')
                    ->label('Var.')
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
            'contracted' => $row('Total contratado', 'contracted'),
            'received' => $row('Total recebido', 'received'),
            'outstanding' => $row('Total por receber', 'outstanding', higherIsBetter: false, highlight: true),
            'overdue' => $row('Por receber vencido', 'overdue', higherIsBetter: false),
            'next30' => [
                'label' => 'A receber nos próximos 30 dias',
                'note' => 'a partir de hoje',
                'current' => PeriodSummary::dueNext30Days(),
                'previous' => null,
                'change' => null,
                'changeColor' => 'gray',
                'highlight' => false,
            ],
            'paidExpenses' => $row('Despesas pagas', 'paidExpenses', higherIsBetter: false),
            'unpaidExpenses' => $row('Despesas por pagar', 'unpaidExpenses', higherIsBetter: false),
            'cashResult' => $row('Resultado de caixa', 'cashResult'),
            'forecastResult' => $row('Resultado previsto', 'forecastResult'),
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
