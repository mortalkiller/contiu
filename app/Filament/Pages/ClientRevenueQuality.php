<?php

namespace App\Filament\Pages;

use App\Filament\Analysis\Widgets\RevenueQualityStats;
use App\Models\Client;
use App\Support\CashForecast;
use App\Support\ClientRevenueQuality as Report;
use App\Support\Money;
use App\Support\Period;
use App\Support\PeriodSummary;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
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

    public ?int $clientId = null;

    public ?string $delayDays = null;

    public function mount(): void
    {
        $this->year ??= today()->year;
    }

    public function content(Schema $schema): Schema
    {
        $years = once(fn (): array => PeriodSummary::availableYears());
        $year = isset($years[$this->year]) ? $this->year : today()->year;
        $rows = once(fn (): array => (new Report(Period::of($year)))->rows());
        $clients = once(fn (): array => Client::query()->orderBy('name')->pluck('name', 'id')->all());
        $clientId = $this->clientId;
        $delay = filter_var($this->delayDays, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => CashForecast::MAX_DELAY_DAYS],
        ]);
        $scenario = isset($clients[$clientId])
            ? once(fn (): array => (new Report(Period::of($year)))->scenario($clientId, $delay === false ? null : $delay))
            : null;

        $biggest = reset($rows);

        return $schema->components([
            ...$this->getWidgetsSchemaComponents([RevenueQualityStats::make(['year' => $year])]),
            Callout::make(fn (): string => __(':client brought in :share% of your receipts', ['client' => $biggest['label'] ?? '—', 'share' => Number::format((float) ($biggest['share'] ?? 0), maxPrecision: 1, locale: app()->getLocale())]))
                ->description(__('Receiving half or more from one client makes you dependent on them. Use the scenario below to see what happens if they pay late or leave.'))
                ->icon(Phosphor::WarningCircle)
                ->status('warning')
                ->visible(($biggest['share'] ?? 0) >= 50),
            Section::make(__('Revenue by client'))
                ->description(__('Concentration uses total payments received in the account. Margin compares contracted amounts with direct costs. Delay is the median number of days late among charges settled this year.'))
                ->schema([
                    Select::make('year')->label(__('Year'))->options($years)->selectablePlaceholder(false)->live(),
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
                            TextEntry::make('received')->money('EUR', divideBy: 100),
                            // A bar: amber from 50% up, where the account depends on one client.
                            ProgressEntry::make('share')->color(fn (?float $state): string => $state >= 50 ? 'warning' : 'primary'),
                            TextEntry::make('margin')->money('EUR', divideBy: 100),
                            TextEntry::make('medianDelay')->formatStateUsing(fn (float $state): string => trans_choice('{1} :days day|:days days', $state, ['days' => Number::format($state, maxPrecision: 1, locale: app()->getLocale())]))->placeholder(__('No settled charges')),
                        ]),
                ]),
            Section::make(__('Cash flow scenario'))
                ->description(__('Compare inflows for this month and the next two without this client or with their payments delayed.'))
                ->columns(3)
                ->schema([
                    Grid::make(2)->columnSpanFull()->schema([
                        Select::make('clientId')->label(__('Client'))->options($clients)->searchable()->live(),
                        TextInput::make('delayDays')
                            ->label(__('Simulated delay'))
                            ->suffix(__('days'))
                            ->integer()
                            ->minValue(0)
                            ->maxValue(CashForecast::MAX_DELAY_DAYS)
                            ->live(onBlur: true)
                            ->disabled($scenario === null)
                            ->helperText(filled($this->delayDays) && $delay === false
                                ? __('Enter a whole number of days between 0 and :max.', ['max' => CashForecast::MAX_DELAY_DAYS])
                                : null),
                    ]),
                    ...($scenario === null ? [] : [
                        TextEntry::make('projected')->label(__('Expected inflows'))->state(Money::format($scenario['inflows'], locale: app()->getLocale())),
                        TextEntry::make('clientProjected')->label(__('Inflows from this client'))->state(Money::format($scenario['clientInflows'], locale: app()->getLocale())),
                        TextEntry::make('otherProjected')->label(__('Inflows without this client'))->state(Money::format($scenario['withoutClient'], locale: app()->getLocale())),
                    ]),
                    ...(! isset($scenario['months']) ? [] : [
                        TextEntry::make('delayedProjected')->label(__('Inflows with delay'))->state(Money::format($scenario['withDelay'], locale: app()->getLocale())),
                        TextEntry::make('beyondHorizon')->label(__('Deferred beyond the forecast'))->state(Money::format($scenario['beyondHorizon'], locale: app()->getLocale())),
                        RepeatableEntry::make('delayMonths')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->state($scenario['months'])
                            ->table([
                                TableColumn::make(__('Month')),
                                TableColumn::make(__('Current forecast')),
                                TableColumn::make(__('With delay')),
                            ])
                            ->schema([
                                TextEntry::make('label'),
                                TextEntry::make('inflows')->money('EUR', divideBy: 100, locale: app()->getLocale()),
                                TextEntry::make('withDelay')->money('EUR', divideBy: 100, locale: app()->getLocale()),
                            ]),
                    ]),
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
