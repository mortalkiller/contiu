<?php

namespace App\Filament\Pages;

use App\Filament\Analysis\Widgets\CashForecastChart;
use App\Filament\Analysis\Widgets\CashForecastStats;
use App\Filament\Analysis\Widgets\CashForecastTable;
use App\Filament\Analysis\Widgets\ForecastInflowsByClientChart;
use App\Filament\Analysis\Widgets\ForecastInflowsChart;
use App\Filament\Analysis\Widgets\ForecastOutflowsChart;
use App\Models\BankTransaction;
use App\Support\CashForecast as Forecast;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;
use App\Support\FiscalReserve;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
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
        return 'Valores em aberto, avenças, despesas recorrentes, Segurança Social e IVA confirmado, mês a mês.';
    }

    protected function getHeaderActions(): array
    {
        $settings = app(FinanceSettings::class);
        $reserve = new FiscalReserve($settings);

        return [
            Action::make('setTaxReserve')
                ->label('Definir reserva fiscal')
                ->icon(Phosphor::Wallet)
                ->visible(fn (): bool => ! CurrentAccount::readOnly())
                ->fillForm(fn (): array => [
                    'amount' => Money::toEuros($settings->taxReservedAmount()),
                    'transfer_id' => $reserve->evidence()['transfer']?->id,
                ])
                ->schema([
                    TextInput::make('amount')
                        ->label('Montante atualmente reservado')
                        ->suffix('€')
                        ->inputMode('decimal')
                        ->regex('/\A[0-9]{1,9}(?:[,.][0-9]{1,2})?\z/')
                        ->required(),
                    Select::make('transfer_id')
                        ->label('Transferência para comprovar a reserva')
                        ->helperText('Opcional. Escolhe uma saída do extrato já classificada como «Transferência minha».')
                        ->searchable()
                        ->preload()
                        ->validationMessages(['in' => FiscalReserve::INVALID_TRANSFER])
                        ->options(fn (): array => self::reserveTransferOptions($reserve))
                        ->getSearchResultsUsing(fn (string $search): array => self::reserveTransferOptions($reserve, $search))
                        ->getOptionLabelUsing(function (mixed $value) use ($reserve): ?string {
                            if (! is_string($value) && ! is_int($value)) {
                                return null;
                            }

                            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                            $transfer = $id === false ? null : $reserve->eligibleTransfers()->find($id);

                            return $transfer === null ? null : self::reserveTransferLabel($transfer);
                        }),
                ])
                ->action(function (array $data) use ($reserve): void {
                    $transferId = filled($data['transfer_id'] ?? null) ? (int) $data['transfer_id'] : null;
                    try {
                        $reserve->save(Money::toCents($data['amount']), $transferId);
                    } catch (ValidationException $exception) {
                        throw ValidationException::withMessages(
                            Arr::prependKeysWith($exception->errors(), $this->getMountedActionSchema()->getStatePath().'.'),
                        );
                    }

                    $this->cacheSchema('content', null);
                }),
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

    /** @return array<int, string> */
    private static function reserveTransferOptions(FiscalReserve $reserve, ?string $search = null): array
    {
        return $reserve->eligibleTransfers()
            ->when(filled($search), fn ($query) => $query->whereLike('description', '%'.$search.'%'))
            ->orderByDesc('booked_on')->orderByDesc('id')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (BankTransaction $transfer): array => [$transfer->id => self::reserveTransferLabel($transfer)])
            ->all();
    }

    private static function reserveTransferLabel(BankTransaction $transfer): string
    {
        return $transfer->booked_on->format('d/m/Y').' · '.$transfer->description.' · '.Money::format($transfer->absolute_amount);
    }

    public function content(Schema $schema): Schema
    {
        $settings = app(FinanceSettings::class);
        $forecast = new Forecast($settings, $this->months);
        $reserve = $forecast->reserve();
        $evidence = (new FiscalReserve($settings))->evidence();
        $transfer = $evidence['transfer'];

        return $schema->components([
            ...($settings->isIncomplete() ? [TaxEstimate::incompleteSettingsCallout()] : []),
            Section::make('Reserva fiscal')
                ->columns(3)
                ->schema([
                    TextEntry::make('reserved')
                        ->label('Montante reservado')
                        ->state(Money::format($reserve['reserved']))
                        ->helperText('Valor separado por ti. Atualiza-o quando reforçares ou usares a reserva.'),
                    TextEntry::make('available')
                        ->label('Saldo disponível após reserva')
                        ->state($reserve['available'] === null ? 'Sem saldo bancário de referência' : Money::format($reserve['available'])),
                    TextEntry::make('tax_paid')
                        ->label('Impostos e contribuições pagos este ano')
                        ->state(Money::format($reserve['paidThisYear']))
                        ->helperText('Despesas pagas na categoria Impostos e contribuições.'),
                    TextEntry::make('reserve_evidence')
                        ->label('Comprovativo da reserva')
                        ->state(match (true) {
                            ! $evidence['linked'] => 'Valor declarado manualmente',
                            $transfer === null => 'Comprovativo inválido',
                            $evidence['unverified'] > 0 => 'Parcialmente comprovada',
                            default => 'Comprovada pela transferência',
                        })
                        ->helperText(match (true) {
                            ! $evidence['linked'] => null,
                            $transfer === null => 'A transferência foi removida ou alterada. O montante continua a ser o valor declarado.',
                            default => self::reserveTransferLabel($transfer),
                        }),
                    ...($transfer === null ? [] : [
                        TextEntry::make('reserve_covered')
                            ->label('Montante comprovado')
                            ->state(Money::format($evidence['covered'])),
                        TextEntry::make('reserve_unverified')
                            ->label('Montante por comprovar')
                            ->state(Money::format($evidence['unverified'])),
                    ]),
                ]),
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
