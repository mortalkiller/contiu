<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Widgets\CashFlowChart;
use App\Filament\Widgets\CompanyAccountCard;
use App\Filament\Widgets\CompanyAccountStats;
use App\Filament\Widgets\ExpensesByCategoryChart;
use App\Filament\Widgets\FinanceStatsOverview;
use App\Filament\Widgets\IncomeByCategoryChart;
use App\Filament\Widgets\MonthComparisonChart;
use App\Filament\Widgets\MonthlyCashFlowTable;
use App\Filament\Widgets\PeriodSummaryTable;
use App\Filament\Widgets\TaxObligationsCard;
use App\Filament\Widgets\UpcomingReceivablesTable;
use App\Support\FinanceSettings;
use App\Support\PendingWork;
use App\Support\Period;
use App\Support\PeriodSummary;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\SelectAction;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFilters;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Support\Enums\FontWeight;
use Illuminate\Support\Str;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class Dashboard extends BaseDashboard
{
    use HasFilters;

    /** First in the menu, as "Hoje"; the page and the browser tab keep the "Painel" title. */
    protected static string|BackedEnum|null $navigationIcon = Phosphor::House;

    protected static ?int $navigationSort = -3;

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
            $hour >= 6 && $hour < 12 => __('Good morning'),
            $hour >= 12 && $hour < 20 => __('Good afternoon'),
            default => __('Good evening'),
        };

        $firstName = Str::before(trim((string) auth()->user()?->name), ' ');

        return filled($firstName) ? $greeting.', '.$firstName : $greeting;
    }

    /**
     * In the menu: how many things need doing ("Por tratar"), red when one of them is already
     * late and amber otherwise. Worked out once per request, for the count and its colour.
     *
     * @return array{count: int, overdue: bool}
     */
    private static function pendingSummary(): array
    {
        return once(fn (): array => (new PendingWork(app(FinanceSettings::class), CarbonImmutable::today()))->summary());
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::pendingSummary()['count'];

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return static::pendingSummary()['overdue'] ? 'danger' : 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return static::pendingSummary()['overdue'] ? __('Things to do, some of them late') : __('Things to do');
    }

    /** The date appears before the dashboard widgets load. */
    public function getSubheading(): string
    {
        return Str::ucfirst(CarbonImmutable::today()->locale(app()->getLocale())->translatedFormat(__('l, F j'))).'.';
    }

    /** A fresh visit shows the whole current year; a year and month in the URL are kept. */
    public function mount(): void
    {
        $this->filters = [
            'year' => (int) ($this->filters['year'] ?? today()->year),
            'month' => filled($this->filters['month'] ?? null) ? (int) $this->filters['month'] : null,
        ];
    }

    /**
     * The period sits in the page header, next to the heading: the year, the month ("Ano inteiro"
     * when none is chosen) and the way into the month close. The two selects write straight to
     * the page filters, which every widget follows.
     */
    protected function getHeaderActions(): array
    {
        return [
            SelectAction::make('filters.year')
                ->label(__('Year'))
                ->options(PeriodSummary::availableYears()),
            SelectAction::make('filters.month')
                ->label(__('Month'))
                ->placeholder(__('Full year'))
                ->options(collect(range(1, 12))->mapWithKeys(fn (int $month): array => [$month => Period::monthName($month)])->all()),
            Action::make('monthClose')
                ->label(__('Month close'))
                ->icon(Phosphor::CalendarCheck)
                ->url(fn (): string => MonthClose::getUrl(['month' => $this->monthCloseMonth()])),
        ];
    }

    /**
     * The monthly cash flow chart makes no sense for a single month, so it gives way to a
     * comparison with the month before. The company account shows here as one section
     * (CompanyAccountCard); its stat cards and the month-by-month table belong to other screens.
     */
    public function getWidgets(): array
    {
        $hidden = [
            filled($this->filters['month'] ?? null) ? CashFlowChart::class : MonthComparisonChart::class,
            CompanyAccountStats::class,
            MonthlyCashFlowTable::class,
        ];

        return array_values(array_filter(Filament::getWidgets(), fn ($widget) => ! in_array($widget, $hidden, true)));
    }

    /**
     * "Por tratar" opens the page, under the heading and its period: it is what needs doing now,
     * whatever period the rest of the dashboard shows.
     */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            ...$this->activityNotOpenedComponents(),
            Grid::make(1)->schema(fn (): array => $this->getWidgetsSchemaComponents($this->onlyWidgets([TaxObligationsCard::class]))),
            $this->getWidgetsContentComponent(),
        ]);
    }

    /**
     * An account created before opening the activity (the sign-up's "Ainda não"): the notice with
     * "Já abri atividade", which asks the fiscal questions left for then (OpenActivity), and the
     * first steps that need no taxes. The bank question left the sign-up for here.
     *
     * @return array<int, Component>
     */
    private function activityNotOpenedComponents(): array
    {
        $waiting = fn (): bool => ! app(FinanceSettings::class)->activityOpened();

        return [
            Callout::make(__('You have not opened your activity yet'))
                ->description(__('When you open it with the Tax Authority, tell us the date and answer a few questions.'))
                ->icon(Phosphor::Info)
                ->controlActions([
                    Action::make('openActivity')
                        ->label(__('I have opened my activity'))
                        ->button()
                        ->size('sm')
                        ->url(fn (): string => OpenActivity::getUrl())
                        ->visible(fn (): bool => OpenActivity::canAccess()),
                ])
                ->visible($waiting),
            Section::make(__('First steps'))
                ->visible($waiting)
                ->schema([
                    $this->firstStep(__('Add your clients'), __('They are ready for the first invoice.'), Action::make('firstClient')->label(__('New client'))->url(fn (): string => ClientResource::getUrl())),
                    $this->firstStep(__('Add your start-up expenses'), __('Computer, software, training.'), Action::make('firstExpense')->label(__('New expense'))->url(fn (): string => ExpenseResource::getUrl('create'))),
                    $this->firstStep(__('Say whether you have a bank account only for the activity'), __('Optional. It left the sign-up for here.'), Action::make('firstBank')->label(__('Set up'))->url(fn (): string => FinanceSettingsPage::getUrl())),
                ]),
        ];
    }

    private function firstStep(string $title, string $detail, Action $action): Flex
    {
        return Flex::make([
            Group::make([
                Text::make($title)->weight(FontWeight::SemiBold),
                Text::make($detail)->color('gray'),
            ])->dense(),
            Actions::make([$action->color('gray')->size('sm')])->grow(false),
        ])->verticallyAlignCenter();
    }

    /**
     * The four figures, then two rows of two (the cash flow next to the company account, when the
     * account has one, and the two doughnuts), then what is still to receive and, under it, the
     * period summary, each across the page.
     */
    public function getWidgetsContentComponent(): Component
    {
        $companyAccount = CompanyAccountCard::canView() ? [CompanyAccountCard::class] : [];
        // Side by side, the sections of a row take the height of the tallest (filament-appearance).
        $sameHeight = ['class' => 'fap-equal-height'];

        return Group::make([
            Grid::make(1)
                ->extraAttributes(['class' => 'contiu-dashboard-stats'])
                ->schema(fn (): array => $this->getWidgetsSchemaComponents($this->onlyWidgets([FinanceStatsOverview::class]))),
            Grid::make(['default' => 1, 'xl' => 3])->extraAttributes($sameHeight)->schema([
                $this->column([CashFlowChart::class, MonthComparisonChart::class], $companyAccount === [] ? 3 : 2),
                $this->column($companyAccount, 1),
            ]),
            Grid::make(['default' => 1, 'md' => 2])->extraAttributes($sameHeight)->schema([
                $this->column([IncomeByCategoryChart::class], 1),
                $this->column([ExpensesByCategoryChart::class], 1),
            ]),
            Grid::make(1)->schema(fn (): array => $this->getWidgetsSchemaComponents($this->onlyWidgets([UpcomingReceivablesTable::class, PeriodSummaryTable::class]))),
        ]);
    }

    /**
     * One cell of a row: the widgets of $classes that this page shows, stacked.
     *
     * @param  array<class-string>  $classes
     */
    private function column(array $classes, int $span): Grid
    {
        return Grid::make(1)
            ->columnSpan(['default' => 1, 'xl' => $span])
            ->visible(fn (): bool => $this->onlyWidgets($classes) !== [])
            ->schema(fn (): array => $this->getWidgetsSchemaComponents($this->onlyWidgets($classes)));
    }

    /**
     * @param  array<class-string>  $classes
     * @return array<class-string>
     */
    private function onlyWidgets(array $classes): array
    {
        return array_values(array_filter($this->getWidgets(), fn ($widget) => in_array($widget, $classes, true)));
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

    #[Unexposed]
    public function getTitle(): string
    {
        return __('Dashboard');
    }

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('Today');
    }
}
