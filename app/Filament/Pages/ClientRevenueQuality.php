<?php

namespace App\Filament\Pages;

use App\Filament\Analysis\Widgets\RevenueQualityStats;
use App\Filament\Support\PeriodPicker;
use App\Livewire\ClientRevenueScenario;
use App\Support\ClientRevenueQuality as Report;
use App\Support\Period;
use App\Support\PeriodSummary;
use BackedEnum;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Support\Number;
use Livewire\Attributes\Url;
use MortalKiller\FilamentAppearance\Infolists\Components\ProgressEntry;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class ClientRevenueQuality extends Page
{
    protected static ?string $slug = 'qualidade-das-receitas';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::ChartPie;

    #[Url]
    public ?int $year = null;

    public function mount(): void
    {
        $this->year ??= today()->year;
    }

    public function getSubheading(): string
    {
        return __('Who you depend on, what each client earns you and how long they take to pay.');
    }

    /** As in the mock: the year to show. */
    protected function getHeaderActions(): array
    {
        $years = collect(once(fn (): array => PeriodSummary::availableYears()))->put(today()->year, (string) today()->year)->sortKeysDesc()->all();

        return [
            PeriodPicker::make('year', $years, $this->year, fn (int|string $year) => $this->year = (int) $year, __('Year')),
        ];
    }

    public function content(Schema $schema): Schema
    {
        $years = once(fn (): array => PeriodSummary::availableYears());
        $year = isset($years[$this->year]) ? $this->year : today()->year;
        $rows = once(fn (): array => (new Report(Period::of($year)))->rows());

        $biggest = reset($rows);

        return $schema->components([
            ...$this->getWidgetsSchemaComponents([RevenueQualityStats::make(['year' => $year])]),
            Callout::make(fn (): string => __(':client brought in :share% of your receipts', ['client' => $biggest['label'] ?? '—', 'share' => Number::format((float) ($biggest['share'] ?? 0), maxPrecision: 1, locale: app()->getLocale())]))
                ->description(__('Receiving half or more from one client makes you dependent on them. Use the scenario below to see what happens if they pay late or leave.'))
                ->icon(Phosphor::WarningCircle)
                ->status('warning')
                ->visible(($biggest['share'] ?? 0) >= 50),
            // As in the mock: the clients, with the late-payment scenario beside them.
            Grid::make(['default' => 1, 'xl' => 3])->schema([
                Section::make(__('Revenue by client'))
                    ->columnSpan(['default' => 1, 'xl' => 2])
                    ->description(__('Concentration uses total payments received in the account. Margin compares contracted amounts with direct costs. Delay is the median number of days late among charges settled this year.'))
                    ->schema([
                        RepeatableEntry::make('clients')
                            ->hiddenLabel()
                            ->placeholder(__('No client transactions this year'))
                            ->state(array_values($rows))
                            ->table([
                                TableColumn::make(__('Client')),
                                TableColumn::make(__('Received')),
                                TableColumn::make(__('Concentration')),
                                TableColumn::make(__('Margin')),
                                TableColumn::make(__('Typical delay')),
                            ])
                            ->schema([
                                TextEntry::make('label'),
                                TextEntry::make('received')->money('EUR', divideBy: 100)->extraAttributes(['class' => 'whitespace-nowrap']),
                                // A bar: amber from 50% up, where the account depends on one client.
                                ProgressEntry::make('share')->color(fn (?float $state): string => $state >= 50 ? 'warning' : 'primary'),
                                TextEntry::make('margin')->money('EUR', divideBy: 100)->extraAttributes(['class' => 'whitespace-nowrap']),
                                TextEntry::make('medianDelay')->formatStateUsing(fn (float $state): string => trans_choice('{1} :days day|:days days', $state, ['days' => Number::format($state, maxPrecision: 1, locale: app()->getLocale())]))->placeholder(__('No settled charges')),
                            ]),
                    ]),
                Livewire::make(ClientRevenueScenario::class),
            ]),
        ]);
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return __('Revenue quality');
    }

    #[Unexposed]
    public static function getNavigationGroup(): string
    {
        return __('Analysis');
    }

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('Revenue quality');
    }
}
