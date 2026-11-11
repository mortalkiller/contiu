<?php

namespace App\Filament\Pages;

use App\Filament\Analysis\Widgets\MarginsBreakdownChart;
use App\Filament\Analysis\Widgets\MarginsByClientChart;
use App\Filament\Analysis\Widgets\MarginsByClientTable;
use App\Filament\Analysis\Widgets\MarginsByLineChart;
use App\Filament\Analysis\Widgets\MarginsByLineTable;
use App\Filament\Analysis\Widgets\MarginsChart;
use App\Filament\Analysis\Widgets\MarginsSummaryStats;
use App\Support\MarginReport;
use App\Support\Period;
use App\Support\PeriodSummary;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Livewire\Attributes\Url;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * Margins per business line and per client, for a year or a month.
 */
class Margins extends Page
{
    protected static ?string $slug = 'margens';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::ChartPie;

    protected static ?int $navigationSort = 2;

    #[Url]
    public ?int $year = null;

    #[Url]
    public ?int $month = null;

    public function mount(): void
    {
        $this->year ??= today()->year;
    }

    public function getSubheading(): string
    {
        return __('Invoiced revenue (contracted in the period) minus direct costs. Overhead is included only in the result.');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(['md' => 4])
                ->schema([
                    Select::make('year')
                        ->label(__('Year'))
                        ->options(once(fn (): array => PeriodSummary::availableYears()))
                        ->selectablePlaceholder(false)
                        ->native(false)
                        ->live(),
                    Select::make('month')
                        ->label(__('Month'))
                        ->placeholder(__('Full year'))
                        ->options(collect(range(1, 12))->mapWithKeys(fn (int $month): array => [$month => Period::monthName($month)])->all())
                        ->native(false)
                        ->live(),
                ]),
            ...$this->getWidgetsSchemaComponents([MarginsSummaryStats::class]),
            // Half or more of the billing from one client is a dependence worth saying out loud.
            Callout::make(fn (): string => __(':client represents :percent% of invoiced revenue', ['client' => $this->biggestClient()['label'], 'percent' => $this->biggestClient()['percent']]))
                ->description(__('When one client accounts for this much, late payments or losing them affects the entire year. See the scenario in Revenue quality.'))
                ->icon(Phosphor::WarningCircle)
                ->status('warning')
                ->visible(fn (): bool => $this->biggestClient()['percent'] >= 50),
            // Two stacked columns: each widget keeps its natural height (no half-empty cards).
            Grid::make(['default' => 1, 'xl' => 3])->schema([
                Grid::make(1)
                    ->columnSpan(['default' => 1, 'xl' => 2])
                    ->schema(fn (): array => $this->getWidgetsSchemaComponents([MarginsChart::class, MarginsByLineTable::class, MarginsByClientTable::class])),
                Grid::make(1)
                    ->schema(fn (): array => $this->getWidgetsSchemaComponents([MarginsByLineChart::class, MarginsByClientChart::class, MarginsBreakdownChart::class])),
            ]),
        ]);
    }

    /**
     * The client with the most billing in the period and their share of it, in whole percent.
     *
     * @return array{label: string, percent: int}
     */
    private function biggestClient(): array
    {
        $year = $this->year ?? today()->year;
        $month = $this->month ?: null;

        return once(function () use ($year, $month): array {
            $clients = collect((new MarginReport(Period::of($year, $month)))->byClient());
            $invoiced = $clients->sum('invoiced');
            $top = $clients->sortByDesc('invoiced')->first();

            return [
                'label' => $top['label'] ?? '—',
                'percent' => $invoiced > 0 && $top !== null ? (int) round($top['invoiced'] / $invoiced * 100) : 0,
            ];
        });
    }

    /** Read lazily by the widgets, so a new year/month reaches them in the same request. */
    public function getWidgetData(): array
    {
        return ['year' => $this->year, 'month' => $this->month ?: null];
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return __('Margins');
    }

    #[Unexposed]
    public static function getNavigationGroup(): string
    {
        return __('Analysis');
    }

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('Margins');
    }
}
