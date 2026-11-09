<?php

namespace App\Filament\Analysis\Widgets;

use App\Support\FinanceSettings;
use App\Support\TaxEstimator;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Livewire\Attributes\Reactive;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

/**
 * Segurança Social per quarter: income, base, monthly contribution and the months it is paid in.
 */
class TaxQuartersTable extends TableWidget
{
    use HasSkeletonPlaceholder;

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
                TextColumn::make('income')->label('Rendimento')->money('EUR', divideBy: 100)->alignEnd(),
                TextColumn::make('base')->label('Base mensal')->money('EUR', divideBy: 100)->alignEnd()->placeholder(''),
                TextColumn::make('monthly')->label('Contribuição mensal')->money('EUR', divideBy: 100)->alignEnd()->placeholder(''),
                TextColumn::make('paidIn')
                    ->label('Pago em')
                    ->description(fn (array $record) => $record['note'])
                    ->placeholder(''),
                TextColumn::make('total')->label('Total')->money('EUR', divideBy: 100)->alignEnd()->weight('semibold'),
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
                'note' => $this->note($quarter),
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

    /**
     * Exempt months (first enrolment or art. 157.º) and whether the contribution is the minimum.
     *
     * @param  array{monthly: int, chargedMonths: int, status: string}  $quarter
     */
    private function note(array $quarter): ?string
    {
        $exemptMonths = 3 - $quarter['chargedMonths'];

        return collect([
            match (true) {
                $exemptMonths === 0 => null,
                $quarter['status'] === 'exempt' => 'Isento (art. 157.º)',
                $exemptMonths === 3 => 'Isento (1.º enquadramento)',
                default => $exemptMonths.' '.($exemptMonths === 1 ? 'mês isento' : 'meses isentos').' (1.º enquadramento)',
            },
            $quarter['chargedMonths'] > 0 && $quarter['monthly'] === TaxEstimator::SS_MINIMUM_CONTRIBUTION ? 'Contribuição mínima' : null,
        ])->filter()->implode(' · ') ?: null;
    }
}
