<?php

namespace App\Filament\Analysis\Widgets;

use App\Filament\Pages\SocialSecurity as SocialSecurityPage;
use App\Support\FinanceSettings;
use App\Support\SocialSecurity;
use App\Support\TaxEstimator;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Livewire\Attributes\Reactive;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

/**
 * Segurança Social per quarter of income: income, base, the monthly contribution it sets for the next
 * quarter and the months that contribution is paid in (each one the month after: Q3 → Nov–Jan).
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
            ->heading(__('Social Security by quarter'))
            ->description(__('Each quarter sets the contributions of the next three months; each month’s is paid between the 10th and the 20th of the following month.'))
            ->headerActions([
                Action::make('socialSecurity')
                    ->label(__('Open Social Security'))
                    ->link()
                    ->url(fn (): string => SocialSecurityPage::getUrl())
                    ->visible(fn (): bool => SocialSecurityPage::canAccess()),
            ])
            ->records(fn (): array => $this->rows())
            ->paginated(false)
            ->recordClasses(fn (array $record) => $record['is_total'] ? 'bg-gray-50 dark:bg-white/5' : null)
            ->columns([
                TextColumn::make('label')->label(__('Quarter'))->weight(fn (array $record) => $record['is_total'] ? 'bold' : 'medium'),
                TextColumn::make('income')->label(__('Earnings'))->money('EUR', divideBy: 100)->alignEnd(),
                TextColumn::make('base')->label(__('Monthly basis'))->money('EUR', divideBy: 100)->alignEnd()->placeholder(''),
                // Blank in a quarter whose payment months are all exempt: nothing is paid.
                TextColumn::make('monthly')->label(__('Monthly contribution'))->money('EUR', divideBy: 100)->alignEnd()->placeholder('')
                    ->state(fn (array $record): ?int => ($record['chargedMonths'] ?? 0) > 0 ? $record['monthly'] : null),
                TextColumn::make('paidIn')
                    ->label(__('Paid in'))
                    ->description(fn (array $record) => $record['note'])
                    ->placeholder(''),
                TextColumn::make('total')->label(__('Total'))->money('EUR', divideBy: 100)->alignEnd()->weight('semibold'),
                // As in the mock: whether each closed quarter's declaration is on record.
                TextColumn::make('declaration')
                    ->label(__('Declaration'))
                    ->state(fn (array $record): ?string => $record['declaration'][0] ?? null)
                    ->badge()
                    ->color(fn (array $record): ?string => $record['declaration'][1] ?? null)
                    ->placeholder(''),
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
                'declaration' => $this->declaration(sprintf('%d-Q%d', $estimator->year, $index + 1)),
                'is_total' => false,
            ];
        }

        $rows['total'] = [
            'label' => __('Year'),
            'income' => $estimator->income(),
            'base' => null,
            'monthly' => null,
            'paidIn' => null,
            'note' => null,
            'total' => $estimator->ssYearTotal(),
            'declaration' => null,
            'is_total' => true,
        ];

        return $rows;
    }

    /**
     * The quarterly declaration of a closed quarter: on record, late (past the end of the month
     * after the quarter) or still due. Nothing for a quarter not yet closed.
     *
     * @return array{0: string, 1: string}|null the label and its colour
     */
    private function declaration(string $quarter): ?array
    {
        $security = app(SocialSecurity::class);

        if ($security->closedQuarter($quarter) === null) {
            return null;
        }

        if ($security->declaration($quarter) !== null) {
            return [__('Filed'), 'success'];
        }

        $dueOn = $security->contributionMonths($quarter)[0]->endOfMonth()->startOfDay();
        $today = CarbonImmutable::today('Europe/Lisbon');

        return $today->gt($dueOn)
            ? [trans_choice('{1} 1 day late|[2,*] :count days late', (int) $dueOn->diffInDays($today)), 'danger']
            : [__('By :date', ['date' => $dueOn->locale(app()->getLocale())->translatedFormat(__('j M'))]), 'warning'];
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
                $quarter['status'] === 'exempt' => __('Exempt (Article 157)'),
                $exemptMonths === 3 => __('Exempt (initial registration)'),
                default => trans_choice('{1} :count exempt month (initial registration)|[2,*] :count exempt months (initial registration)', $exemptMonths),
            },
            $quarter['chargedMonths'] > 0 && $quarter['monthly'] === TaxEstimator::SS_MINIMUM_CONTRIBUTION ? __('Minimum contribution') : null,
        ])->filter()->implode(' · ') ?: null;
    }
}
