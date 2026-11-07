<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\CashFlowChart;
use App\Filament\Widgets\ExpensesByCategoryChart;
use App\Filament\Widgets\FinanceStatsOverview;
use App\Filament\Widgets\IncomeByCategoryChart;
use App\Filament\Widgets\MonthComparisonChart;
use App\Filament\Widgets\MonthlyCashFlowTable;
use App\Filament\Widgets\PeriodSummaryTable;
use App\Filament\Widgets\UpcomingReceivablesTable;
use App\Support\Period;
use App\Support\PeriodSummary;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\VerticalAlignment;
use Illuminate\Support\Str;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static ?string $title = 'Painel';

    /** The logo already leads to the dashboard. */
    protected static bool $shouldRegisterNavigation = false;

    /** Always open on the current year instead of the last period looked at. */
    public function persistsFiltersInSession(): bool
    {
        return false;
    }

    /** "Bom dia, Pedro" — the browser tab keeps the "Painel" title. */
    public function getHeading(): string
    {
        $hour = now()->hour;

        $greeting = match (true) {
            $hour >= 6 && $hour < 12 => 'Bom dia',
            $hour >= 12 && $hour < 20 => 'Boa tarde',
            default => 'Boa noite',
        };

        $firstName = Str::before(trim((string) auth()->user()?->name), ' ');

        return filled($firstName) ? $greeting.', '.$firstName : $greeting;
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columnSpanFull()
                ->columns(['md' => 4])
                ->schema([
                    Select::make('year')
                        ->label('Ano')
                        ->options(PeriodSummary::availableYears())
                        ->default(today()->year)
                        ->selectablePlaceholder(false)
                        ->native(false),
                    Select::make('month')
                        ->label('Mês')
                        ->placeholder('Ano inteiro')
                        ->options(collect(Period::MONTHS)->mapWithKeys(fn (string $name, int $index) => [$index + 1 => $name])->all())
                        ->native(false),
                    Actions::make([
                        Action::make('monthClose')
                            ->label('Fecho do mês')
                            ->icon(Phosphor::CalendarCheck)
                            ->url(fn () => MonthClose::getUrl(['month' => $this->monthCloseMonth()])),
                    ])
                        ->columnSpan(['md' => 2])
                        ->alignment(Alignment::End)
                        ->verticalAlignment(VerticalAlignment::End),
                ]),
        ]);
    }

    /**
     * The monthly cash flow chart makes no sense for a single month, so it gives way to a
     * comparison with the month before.
     */
    public function getWidgets(): array
    {
        $hidden = filled($this->filters['month'] ?? null) ? CashFlowChart::class : MonthComparisonChart::class;

        return array_values(array_filter(Filament::getWidgets(), fn ($widget) => $widget !== $hidden));
    }

    /** Wide column: the charts and tables that need room. */
    public const MAIN_COLUMN = [CashFlowChart::class, MonthComparisonChart::class, MonthlyCashFlowTable::class, UpcomingReceivablesTable::class];

    /** Narrow column: the doughnuts and the period summary. */
    public const SIDE_COLUMN = [IncomeByCategoryChart::class, ExpensesByCategoryChart::class, PeriodSummaryTable::class];

    /**
     * Stats on top, then two stacked columns instead of one grid: in a grid every row takes the
     * height of its tallest widget, which left short widgets in tall, half-empty cards.
     */
    public function getWidgetsContentComponent(): Component
    {
        $only = fn (array $classes): array => array_values(array_filter($this->getWidgets(), fn ($widget) => in_array($widget, $classes, true)));

        return Group::make([
            Grid::make(1)->schema(fn (): array => $this->getWidgetsSchemaComponents($only([FinanceStatsOverview::class]))),
            Grid::make(['default' => 1, 'xl' => 3])->schema([
                Grid::make(1)
                    ->columnSpan(['default' => 1, 'xl' => 2])
                    ->schema(fn (): array => $this->getWidgetsSchemaComponents($only(self::MAIN_COLUMN))),
                Grid::make(1)
                    ->columnSpan(1)
                    ->schema(fn (): array => $this->getWidgetsSchemaComponents($only(self::SIDE_COLUMN))),
            ]),
        ]);
    }

    public function getColumns(): int|array
    {
        return ['md' => 2, 'xl' => 3];
    }

    /** The chosen month, or the current month (of the chosen year when it isn't this year). */
    private function monthCloseMonth(): string
    {
        $year = (int) ($this->filters['year'] ?? today()->year);
        $month = filled($this->filters['month'] ?? null) ? (int) $this->filters['month'] : ($year === today()->year ? today()->month : 12);

        return sprintf('%04d-%02d', $year, $month);
    }
}
