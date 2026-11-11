<?php

namespace App\Filament\Pages;

use App\Filament\Analysis\Widgets\MarginsByClientTable;
use App\Filament\Analysis\Widgets\MarginsByLineTable;
use App\Filament\Analysis\Widgets\MarginsSummaryStats;
use App\Filament\Support\PeriodPicker;
use App\Filament\Support\ProportionBar;
use App\Support\MarginReport;
use App\Support\Period;
use App\Support\PeriodSummary;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Support\HtmlString;
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
        return __('What you invoiced, less the direct costs of each project and retainer.');
    }

    /** As in the mock: the year, then a month or the whole year. */
    protected function getHeaderActions(): array
    {
        $months = [0 => __('Full year')] + collect(range(1, 12))->mapWithKeys(fn (int $month): array => [$month => Period::monthName($month)])->all();

        return [
            PeriodPicker::make('year', collect(once(fn (): array => PeriodSummary::availableYears()))->put(today()->year, (string) today()->year)->sortKeysDesc()->all(), $this->year, fn (int|string $year) => $this->year = (int) $year, __('Year')),
            PeriodPicker::make('month', $months, (int) ($this->month ?? 0), fn (int|string $month) => $this->month = ((int) $month) ?: null, __('Month')),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            ...$this->getWidgetsSchemaComponents([MarginsSummaryStats::class]),
            Section::make(__('Where the invoiced money goes'))
                ->schema([
                    TextEntry::make('breakdown')->hiddenLabel()->state(fn (): HtmlString|string => $this->breakdown()),
                ]),
            // As in the mock: by client and by business line, side by side.
            Grid::make(['default' => 1, '2xl' => 2])->schema([
                Grid::make(1)->schema(fn (): array => [
                    ...$this->getWidgetsSchemaComponents([MarginsByClientTable::class]),
                    // Half or more of the billing from one client is a dependence worth saying out loud.
                    Callout::make(__(':client represents :percent% of invoiced revenue', ['client' => $this->biggestClient()['label'], 'percent' => $this->biggestClient()['percent']]))
                        ->description(__('When one client accounts for this much, late payments or losing them affects the entire year. See the scenario in Revenue quality.'))
                        ->icon(Phosphor::Info)
                        ->status('info')
                        ->actions([
                            Action::make('revenueQuality')->label(__('Revenue quality'))->link()->url(ClientRevenueQuality::getUrl(['year' => $this->year])),
                        ])
                        ->visible($this->biggestClient()['percent'] >= 50),
                ]),
                Grid::make(1)->schema(fn (): array => $this->getWidgetsSchemaComponents([MarginsByLineTable::class])),
            ]),
        ]);
    }

    /** "Para onde vai o faturado": the result, the direct costs and the overhead, as one bar. */
    private function breakdown(): HtmlString|string
    {
        $totals = (new MarginReport(Period::of($this->year ?? today()->year, $this->month ?: null)))->totals();

        if ($totals['invoiced'] <= 0) {
            return __('No invoiced revenue');
        }

        return ProportionBar::render([
            ['label' => __('Result'), 'cents' => max(0, $totals['result']), 'tone' => 'strong'],
            ['label' => __('Direct costs'), 'cents' => $totals['directCosts'], 'tone' => 'soft'],
            ['label' => __('Overhead costs'), 'cents' => $totals['overhead'], 'tone' => 'clay'],
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
