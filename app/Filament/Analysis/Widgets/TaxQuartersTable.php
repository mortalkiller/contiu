<?php

namespace App\Filament\Analysis\Widgets;

use App\Support\FinanceSettings;
use App\Support\TaxEstimator;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Livewire\Attributes\Reactive;

/**
 * Segurança Social per quarter: income, base, monthly contribution and the months it is paid in.
 */
class TaxQuartersTable extends TableWidget
{
    #[Reactive]
    public ?int $year = null;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Segurança Social por trimestre')
            ->description('A contribuição de cada trimestre é paga nos três meses seguintes.')
            ->records(fn (): array => $this->rows())
            ->paginated(false)
            ->recordClasses(fn (array $record) => $record['is_total'] ? 'bg-gray-50 dark:bg-white/5' : null)
            ->columns([
                TextColumn::make('label')->label('Trimestre')->weight(fn (array $record) => $record['is_total'] ? 'bold' : 'medium'),
                TextColumn::make('income')->label('Rendimento')->money('EUR')->alignEnd(),
                TextColumn::make('base')->label('Base mensal')->money('EUR')->alignEnd()->placeholder(''),
                TextColumn::make('monthly')->label('Contribuição mensal')->money('EUR')->alignEnd()->placeholder(''),
                TextColumn::make('paidIn')
                    ->label('Pago em')
                    ->description(fn (array $record) => $record['note'])
                    ->placeholder(''),
                TextColumn::make('total')->label('Total')->money('EUR')->alignEnd()->weight('semibold'),
            ]);
    }

    /** @return array<string, array<string, mixed>> */
    private function rows(): array
    {
        $estimator = new TaxEstimator(app(FinanceSettings::class), $this->year ?? today()->year);
        $rows = [];

        foreach ($estimator->quarters() as $index => $quarter) {
            $rows['q'.($index + 1)] = [
                ...$quarter,
                'note' => match ($quarter['chargedMonths']) {
                    3 => null,
                    0 => 'Isento (1.º ano de atividade)',
                    default => (3 - $quarter['chargedMonths']).' '.((3 - $quarter['chargedMonths']) === 1 ? 'mês isento' : 'meses isentos'),
                },
                'is_total' => false,
            ];
        }

        $rows['total'] = [
            'label' => 'Ano',
            'income' => $estimator->income(),
            'base' => null,
            'monthly' => null,
            'paidIn' => null,
            'note' => null,
            'total' => $estimator->ssYearTotal(),
            'is_total' => true,
        ];

        return $rows;
    }
}
