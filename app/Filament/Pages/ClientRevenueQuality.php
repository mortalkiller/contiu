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
use Filament\Infolists\Components\ViewEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Number;
use Livewire\Attributes\Url;
use ToneGabes\Filament\Icons\Enums\Phosphor;
use UnitEnum;

class ClientRevenueQuality extends Page
{
    protected static ?string $title = 'Qualidade das receitas';

    protected static ?string $slug = 'qualidade-das-receitas';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::ChartPie;

    protected static string|UnitEnum|null $navigationGroup = 'Análise';

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
        $years = PeriodSummary::availableYears();
        $year = isset($years[$this->year]) ? $this->year : today()->year;
        $report = new Report(Period::of($year));
        $clients = Client::query()->orderBy('name')->pluck('name', 'id')->all();
        $delay = filter_var($this->delayDays, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => CashForecast::MAX_DELAY_DAYS],
        ]);
        $scenario = isset($clients[$this->clientId])
            ? $report->scenario($this->clientId, $delay === false ? null : $delay)
            : null;

        $biggest = collect($report->rows())->first();

        return $schema->components([
            ...$this->getWidgetsSchemaComponents([RevenueQualityStats::make(['year' => $year])]),
            Callout::make(fn (): string => ($biggest['label'] ?? '—').' trouxe '.Number::format((float) ($biggest['share'] ?? 0), maxPrecision: 1, locale: 'pt_PT').'% do que recebeste')
                ->description('Metade ou mais vinda de um só cliente é dependência. Simula abaixo o que acontece se ele atrasar ou sair.')
                ->icon(Phosphor::WarningCircle)
                ->status('warning')
                ->visible(($biggest['share'] ?? 0) >= 50),
            Section::make('Receitas por cliente')
                ->description('A concentração usa o total recebido na conta. A margem compara o contratado com os custos diretos. O atraso é a mediana dos dias de atraso nas cobranças do ano já liquidadas.')
                ->schema([
                    Select::make('year')->label('Ano')->options($years)->selectablePlaceholder(false)->live(),
                    RepeatableEntry::make('clients')
                        ->hiddenLabel()
                        ->placeholder('Sem movimentos para os clientes neste ano')
                        ->state(fn (): array => array_values($report->rows()))
                        ->table([
                            TableColumn::make('Cliente'),
                            TableColumn::make('Recebido'),
                            TableColumn::make('Concentração'),
                            TableColumn::make('Margem'),
                            TableColumn::make('Atraso habitual'),
                        ])
                        ->schema([
                            TextEntry::make('label'),
                            TextEntry::make('received')->money('EUR', divideBy: 100),
                            // A bar: amber from 50% up, where the account depends on one client.
                            ViewEntry::make('share')->view('filament.infolists.percent-bar', ['tone' => 'share']),
                            TextEntry::make('margin')->money('EUR', divideBy: 100),
                            TextEntry::make('medianDelay')->formatStateUsing(fn (float $state): string => Number::format($state, maxPrecision: 1, locale: 'pt_PT').' dias')->placeholder('Sem cobranças liquidadas'),
                        ]),
                ]),
            Section::make('Cenário de tesouraria')
                ->description('Compara as entradas deste mês e dos dois seguintes sem o cliente ou com os seus pagamentos adiados.')
                ->columns(3)
                ->schema([
                    Grid::make(2)->columnSpanFull()->schema([
                        Select::make('clientId')->label('Cliente')->options($clients)->searchable()->live(),
                        TextInput::make('delayDays')
                            ->label('Atraso simulado')
                            ->suffix('dias')
                            ->integer()
                            ->minValue(0)
                            ->maxValue(CashForecast::MAX_DELAY_DAYS)
                            ->live(onBlur: true)
                            ->disabled($scenario === null)
                            ->helperText(filled($this->delayDays) && $delay === false
                                ? 'Indica um número inteiro de dias entre 0 e '.CashForecast::MAX_DELAY_DAYS.'.'
                                : null),
                    ]),
                    ...($scenario === null ? [] : [
                        TextEntry::make('projected')->label('Entradas previstas')->state(Money::format($scenario['inflows'])),
                        TextEntry::make('clientProjected')->label('Entradas deste cliente')->state(Money::format($scenario['clientInflows'])),
                        TextEntry::make('otherProjected')->label('Entradas sem este cliente')->state(Money::format($scenario['withoutClient'])),
                    ]),
                    ...(! isset($scenario['months']) ? [] : [
                        TextEntry::make('delayedProjected')->label('Entradas com atraso')->state(Money::format($scenario['withDelay'])),
                        TextEntry::make('beyondHorizon')->label('Adiado para depois do horizonte')->state(Money::format($scenario['beyondHorizon'])),
                        RepeatableEntry::make('delayMonths')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->state($scenario['months'])
                            ->table([
                                TableColumn::make('Mês'),
                                TableColumn::make('Previsão atual'),
                                TableColumn::make('Com atraso'),
                            ])
                            ->schema([
                                TextEntry::make('label'),
                                TextEntry::make('inflows')->money('EUR', divideBy: 100, locale: 'pt_PT'),
                                TextEntry::make('withDelay')->money('EUR', divideBy: 100, locale: 'pt_PT'),
                            ]),
                    ]),
                ]),
        ]);
    }
}
