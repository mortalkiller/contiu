<?php

namespace App\Filament\Pages;

use App\Enums\Language;
use App\Filament\Analysis\Widgets\CashForecastChart;
use App\Filament\Analysis\Widgets\CashForecastStats;
use App\Filament\Analysis\Widgets\CashForecastTable;
use App\Filament\Support\ProportionBar;
use App\Models\BankTransaction;
use App\Support\CashForecast as Forecast;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;
use App\Support\FiscalReserve;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * "Previsão de tesouraria": what should come in and go out over the next 3 or 6 months.
 */
class CashForecast extends Page
{
    protected static ?string $slug = 'previsao-de-tesouraria';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::ChartLineUp;

    protected static ?int $navigationSort = 1;

    #[Url]
    public int $months = 3;

    /** @var array<int, array{forecast: Forecast, reserve: array, evidence: array}> */
    private array $forecastOverview = [];

    public function mount(): void
    {
        $this->months = in_array($this->months, [3, 6], true) ? $this->months : 3;
    }

    public function getSubheading(): string
    {
        return __('Outstanding amounts, retainers, recurring expenses, Social Security and confirmed VAT, month by month.');
    }

    protected function getHeaderActions(): array
    {
        $settings = app(FinanceSettings::class);
        $reserve = new FiscalReserve($settings);

        return [
            ActionGroup::make([
                Action::make('threeMonths')
                    ->label(__('3 months'))
                    ->color(fn () => $this->months === 3 ? 'primary' : 'gray')
                    ->action(fn () => $this->months = 3),
                Action::make('sixMonths')
                    ->label(__('6 months'))
                    ->color(fn () => $this->months === 6 ? 'primary' : 'gray')
                    ->action(fn () => $this->months = 6),
            ])->buttonGroup(),
            Action::make('setTaxReserve')
                ->label(__('Set tax reserve'))
                ->color('gray')
                ->visible(fn (): bool => ! CurrentAccount::readOnly())
                ->fillForm(fn (): array => [
                    'amount' => Money::toEuros($settings->taxReservedAmount()),
                    'transfer_id' => $reserve->evidence()['transfer']?->id,
                ])
                ->schema([
                    TextInput::make('amount')
                        ->label(__('Amount currently reserved'))
                        ->suffix('€')
                        ->inputMode('decimal')
                        ->regex('/\A[0-9]{1,9}(?:[,.][0-9]{1,2})?\z/')
                        ->required(),
                    Select::make('transfer_id')
                        ->label(__('Transfer used as proof of the reserve'))
                        ->helperText(__('Optional. Choose a statement outflow already classified as “Transfer between my accounts”.'))
                        ->searchable()
                        ->preload()
                        ->validationMessages(['in' => __(FiscalReserve::INVALID_TRANSFER)])
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

                    $this->forecastOverview = [];
                    $this->cacheSchema('content', null);
                }),
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
        return $transfer->booked_on->format(Language::resolve(app()->getLocale())->dateFormat()).' · '.$transfer->description.' · '.Money::format($transfer->absolute_amount, locale: app()->getLocale());
    }

    public function content(Schema $schema): Schema
    {
        $settings = app(FinanceSettings::class);
        ['forecast' => $forecast, 'reserve' => $reserve, 'evidence' => $evidence] = $this->forecastOverview[$this->months] ??= [
            'forecast' => $forecast = Forecast::forRequest($this->months),
            'reserve' => $forecast->reserve(),
            'evidence' => (new FiscalReserve($settings))->evidence(),
        ];
        $transfer = $evidence['transfer'];

        $locale = app()->getLocale();

        return $schema->components([
            ...($settings->isIncomplete() ? [TaxEstimate::incompleteSettingsCallout()] : []),
            // Where the running balance starts is in the stats; only a missing balance needs a word here.
            ...match (true) {
                ! $forecast->showsBalance() => [Callout::make(__('No balance'))
                    ->description(__('Without a dedicated business account, the forecast shows monthly inflows and outflows only.'))
                    ->icon(Phosphor::Wallet)
                    ->status('info')],
                $forecast->startsFromBankBalance() => [],
                default => [Callout::make(__('The running balance starts at €0'))
                    ->description(__('Import your bank statement to use the actual account balance in the forecast.'))
                    ->icon(Phosphor::Wallet)
                    ->status('warning')
                    ->actions([
                        Action::make('statement')
                            ->label(__('Open bank statement'))
                            ->link()
                            ->url(BankStatementImport::getUrl()),
                    ])],
            },
            ...$this->getWidgetsSchemaComponents([CashForecastStats::class]),
            // As in the mock: the balance and the months on the left; the reserve and where the
            // money comes from and goes on the right.
            Grid::make(['default' => 1, 'xl' => 3])->schema([
                Grid::make(1)
                    ->columnSpan(['default' => 1, 'xl' => 2])
                    ->schema(fn (): array => $this->getWidgetsSchemaComponents([CashForecastChart::class, CashForecastTable::class])),
                Grid::make(1)->schema([
                    Section::make(__('Tax reserve'))
                        ->inlineLabel()
                        ->extraAttributes(['class' => 'fap-key-value'])
                        ->schema([
                            TextEntry::make('reserved')
                                ->label(__('Amount reserved'))
                                ->state(Money::format($reserve['reserved'], locale: $locale)),
                            TextEntry::make('tax_paid')
                                ->label(__('Taxes and contributions paid this year'))
                                ->state(Money::format($reserve['paidThisYear'], locale: $locale)),
                            TextEntry::make('available')
                                ->label(__('Available balance after reserve'))
                                ->weight('semibold')
                                ->state($reserve['available'] === null ? __('No reference bank balance') : Money::format($reserve['available'], locale: $locale)),
                            TextEntry::make('reserve_evidence')
                                ->label(__('Proof of reserve'))
                                ->state(match (true) {
                                    ! $evidence['linked'] => __('Manually declared amount'),
                                    $transfer === null => __('Invalid proof'),
                                    $evidence['unverified'] > 0 => __('Partially verified'),
                                    default => __('Verified by the transfer'),
                                }),
                            ...($transfer === null ? [] : [
                                TextEntry::make('reserve_covered')
                                    ->label(__('Verified amount'))
                                    ->state(Money::format($evidence['covered'], locale: $locale)),
                                TextEntry::make('reserve_unverified')
                                    ->label(__('Unverified amount'))
                                    ->state(Money::format($evidence['unverified'], locale: $locale)),
                            ]),
                            TextEntry::make('reserve_note')
                                ->hiddenLabel()
                                ->color('gray')
                                ->state(match (true) {
                                    $evidence['linked'] && $transfer === null => __('The transfer was removed or changed. The declared amount still applies.'),
                                    $transfer !== null => self::reserveTransferLabel($transfer),
                                    default => __('Amount you set aside. Update it when you add to or use the reserve.'),
                                }),
                        ]),
                    Section::make(__('Where it comes from and where it goes'))
                        ->schema(fn (): array => $this->flowBars($forecast)),
                ]),
            ]),
        ]);
    }

    /**
     * "De onde vem e para onde vai": the expected inflows and outflows split by source, as bars;
     * over six months, the inflows by client too.
     *
     * @return list<TextEntry>
     */
    private function flowBars(Forecast $forecast): array
    {
        $segments = function (array $amounts, array $tones): array {
            $segments = [];

            foreach ($amounts as $label => $cents) {
                if ($cents > 0) {
                    $segments[] = ['label' => (string) $label, 'cents' => $cents, 'tone' => $tones[count($segments)] ?? 'soft'];
                }
            }

            return $segments;
        };
        $bar = fn (string $name, string $label, array $amounts, array $tones): TextEntry => TextEntry::make($name)
            ->label($label)
            ->state(array_sum($amounts) > 0 ? ProportionBar::render($segments($amounts, $tones)) : __('Nothing expected'));

        $entries = [
            $bar('inflow_split', __('Expected inflows'), $forecast->inflowBreakdown(), ['strong', 'danger', 'medium']),
            $bar('outflow_split', __('Expected outflows'), $forecast->outflowBreakdown(), ['clay', 'danger', 'medium', 'strong', 'soft']),
        ];

        if ($this->months === 6) {
            $entries[] = $bar('inflow_clients', __('Expected inflows by client'), array_slice($forecast->inflowsByClient(), 0, 4, true), ['strong', 'medium', 'soft', 'clay']);
        }

        return $entries;
    }

    /** Read lazily by the widgets, so the 3/6-month toggle reaches them in the same request. */
    public function getWidgetData(): array
    {
        return ['months' => $this->months];
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return __('Cash forecast');
    }

    #[Unexposed]
    public static function getNavigationGroup(): string
    {
        return __('Analysis');
    }

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('Cash forecast');
    }
}
