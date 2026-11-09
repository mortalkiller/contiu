<?php

namespace App\Filament\Pages;

use App\Filament\Analysis\Widgets\CashForecastChart;
use App\Filament\Analysis\Widgets\CashForecastStats;
use App\Filament\Analysis\Widgets\CashForecastTable;
use App\Filament\Analysis\Widgets\ForecastInflowsByClientChart;
use App\Filament\Analysis\Widgets\ForecastInflowsChart;
use App\Filament\Analysis\Widgets\ForecastOutflowsChart;
use App\Support\CashForecast as Forecast;
use App\Support\FinanceSettings;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Schema;
use Livewire\Attributes\Url;
use ToneGabes\Filament\Icons\Enums\Phosphor;
use UnitEnum;

/**
 * "Previsão de tesouraria": what should come in and go out over the next 3 or 6 months.
 */
class CashForecast extends Page
{
    protected static ?string $title = 'Previsão de tesouraria';

    protected static ?string $slug = 'previsao-de-tesouraria';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::ChartLineUp;

    protected static string|UnitEnum|null $navigationGroup = 'Análise';

    protected static ?int $navigationSort = 1;

    #[Url]
    public int $months = 3;

    public function mount(): void
    {
        $this->months = in_array($this->months, [3, 6], true) ? $this->months : 3;
    }

    public function getSubheading(): string
    {
        return 'Valores em aberto, avenças e despesas recorrentes ainda por gerar e Segurança Social estimada, mês a mês.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('threeMonths')
                ->label('3 meses')
                ->color(fn () => $this->months === 3 ? 'primary' : 'gray')
                ->action(fn () => $this->months = 3),
            Action::make('sixMonths')
                ->label('6 meses')
                ->color(fn () => $this->months === 6 ? 'primary' : 'gray')
                ->action(fn () => $this->months = 6),
        ];
    }

    public function content(Schema $schema): Schema
    {
        $settings = app(FinanceSettings::class);
        $forecast = new Forecast($settings, $this->months);

        return $schema->components([
            ...($settings->isIncomplete() ? [TaxEstimate::incompleteSettingsCallout()] : []),
            ! $forecast->showsBalance()
                ? Callout::make('Sem saldo')
                    ->description('Sem conta bancária só para a atividade, a previsão mostra o que entra e sai em cada mês, não o dinheiro que vais ter.')
                    ->icon(Phosphor::Wallet)
                    ->status('info')
                : ($forecast->startsFromBankBalance()
                ? Callout::make('Começa em '.Money::format($forecast->startingBalance()))
                    ->description('Saldo do extrato até '.$forecast->account()->endsOn()?->format('d/m/Y').', mais o que registaste desde então. Importa o extrato do banco para o manter certo.')
                    ->icon(Phosphor::Wallet)
                    ->status('info')
                : Callout::make('O saldo acumulado começa em 0 €')
                    ->description('Para uma previsão real, importa o extrato do banco: o saldo da conta vem de lá.')
                    ->icon(Phosphor::Wallet)
                    ->status('warning')
                    ->actions([
                        Action::make('statement')
                            ->label('Abrir Extrato bancário')
                            ->link()
                            ->url(BankStatementImport::getUrl()),
                    ])),
            ...$this->getWidgetsSchemaComponents([CashForecastStats::class]),
            // Two stacked columns: each widget keeps its natural height (no half-empty cards).
            Grid::make(['default' => 1, 'xl' => 3])->schema([
                Grid::make(1)
                    ->columnSpan(['default' => 1, 'xl' => 2])
                    ->schema(fn (): array => $this->getWidgetsSchemaComponents([CashForecastChart::class, CashForecastTable::class])),
                // Keyed by the horizon so the doughnuts are remounted with the new figures when the 3/6
                // toggle changes (Chart.js keeps its first canvas otherwise).
                Grid::make(1)
                    ->schema(fn (): array => collect($this->sideWidgets())
                        ->map(fn (string $widget): Livewire => Livewire::make($widget, fn (): array => $this->getWidgetData())
                            ->key($widget.'-'.$this->months.'m'))
                        ->all()),
            ]),
        ]);
    }

    /**
     * Doughnuts beside the chart and table. The 6-month table is longer, so that horizon also gets
     * the by-client split and both columns end level.
     *
     * @return array<class-string>
     */
    public function sideWidgets(): array
    {
        return $this->months === 6
            ? [ForecastInflowsChart::class, ForecastOutflowsChart::class, ForecastInflowsByClientChart::class]
            : [ForecastInflowsChart::class, ForecastOutflowsChart::class];
    }

    /** Read lazily by the widgets, so the 3/6-month toggle reaches them in the same request. */
    public function getWidgetData(): array
    {
        return ['months' => $this->months];
    }
}
