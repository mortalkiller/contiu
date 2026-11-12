<?php

namespace App\Filament\Pages;

use App\Enums\PortugueseVatRegion;
use App\Filament\Pages\Concerns\HasTaxSubNavigation;
use App\Models\FiscalDocument;
use App\Models\Payment;
use App\Support\CurrentAccount;
use App\Support\DocumentedTurnover;
use App\Support\FinanceSettings;
use App\Support\IrsScenario;
use App\Support\IrsSettlementEstimate;
use App\Support\Money;
use App\Support\TaxEstimator;
use App\Support\TerritorialTurnover;
use App\Support\WithholdingReview;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Livewire\Attributes\Locked;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class IrsScenarioPage extends Page
{
    use HasTaxSubNavigation;

    protected static ?string $slug = 'cenario-irs';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::Calculator;

    protected static ?int $navigationSort = 8;

    /** True in the browser while the chosen fiscal residence has no settlement figure (RA001). */
    private const string RESIDENCE_TO_CONFIRM_JS = "\$get('residence') === 'PT-AC'";

    protected string $view = 'filament.pages.irs-scenario';

    /** @var array<string, mixed> */
    public array $data = [];

    #[Locked]
    public int $accountId;

    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $result = null;

    public static function canAccess(): bool
    {
        return CurrentAccount::get() !== null
            && Gate::allows('viewAny', FiscalDocument::class)
            && Gate::allows('viewAny', Payment::class);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->accountId = CurrentAccount::id();
        $this->form->fill([
            'year' => today()->year,
            'residence' => (app(FinanceSettings::class)->vatRegion() ?? PortugueseVatRegion::Mainland)->value,
        ]);
    }

    public function getSubheading(): string
    {
        return __('See what changes in your year if you take on a new retainer.');
    }

    public function hydrate(): void
    {
        abort_unless(static::canAccess() && $this->accountId === CurrentAccount::id(), 403);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make(__('Year and new retainer'))
                ->columns(2)
                ->schema([
                    TextInput::make('year')->label(__('Year'))
                        ->integer()->minValue(1900)->maxValue(9998)->required()
                        ->live(onBlur: true)
                        ->validationMessages([
                            'integer' => __('Enter a whole year.'),
                            'min' => __('Enter a year from 1900 onward.'),
                            'max' => __('Enter a year no later than 9998.'),
                        ]),
                    TextInput::make('months')->label(__('Months'))
                        ->integer()->minValue(1)->maxValue(12)->required()
                        ->validationMessages([
                            'integer' => __('Enter a whole number of months.'),
                            'min' => __('Enter at least one month.'),
                            'max' => __('Enter no more than 12 months.'),
                        ]),
                    $this->moneyInput('monthlyNet')->label(__('Monthly amount excluding VAT'))
                        ->columnSpanFull()
                        ->helperText(__('Monthly amount to invoice before withholding.'))
                        ->required()
                        ->rule('not_regex:/\A0+(?:[,.]0+)?\z/')
                        ->validationMessages([
                            'regex' => __('Enter an amount in euros with up to two decimal places.'),
                            'not_regex' => __('Enter a monthly amount greater than zero.'),
                        ]),
                    $this->moneyInput('scenarioRetention')->label(__('Withholding on the new retainer in the year'))
                        ->columnSpanFull()
                        ->helperText(__('Optional. Enter the total for the selected months. Use €0 if that is the amount for this scenario.')),
                ]),
            Section::make(__('Personal income tax assessment estimate'))
                ->description(__('Available for 2026 income, with individual taxation at the general rates of your fiscal residence.'))
                ->visible(fn (Get $get): bool => (int) $get('year') === IrsSettlementEstimate::YEAR)
                ->schema([
                    Select::make('residence')->label(__('Fiscal residence'))
                        ->options(collect(PortugueseVatRegion::cases())
                            ->mapWithKeys(fn (PortugueseVatRegion $region): array => [$region->value => $region->label()])
                            ->all())
                        ->helperText(__('Where you live for tax purposes, not where your clients are. It starts from the VAT region in your tax settings.'))
                        ->selectablePlaceholder(false)
                        ->required(),
                    Callout::make(__('To confirm'))
                        ->description(IrsSettlementEstimate::toConfirm(PortugueseVatRegion::Azores))
                        ->icon(Phosphor::WarningCircle)
                        ->status('warning')
                        ->visibleJs(self::RESIDENCE_TO_CONFIRM_JS),
                    Toggle::make('estimateSettlement')->label(__('Calculate with verified tax details'))
                        ->hiddenJs(self::RESIDENCE_TO_CONFIRM_JS),
                    Section::make(__('Current scenario'))->schema($this->settlementInputs('baseline'))
                        ->visibleJs("!! \$get('estimateSettlement') && ! (".self::RESIDENCE_TO_CONFIRM_JS.')'),
                    Section::make(__('Scenario with the new retainer'))->schema($this->settlementInputs('combined'))
                        ->visibleJs("!! \$get('estimateSettlement') && ! (".self::RESIDENCE_TO_CONFIRM_JS.')'),
                ]),
        ]);
    }

    /**
     * What the year already has on record, before any scenario: documented billing, documented
     * withholding and, while exempt from VAT, the share of the exemption limit used.
     *
     * @return array{summary: list<array<string, mixed>>, summaryNotice: array{title: string, body: string}}
     */
    protected function getViewData(): array
    {
        $year = (int) ($this->data['year'] ?? 0);
        $year = $year >= 1900 && $year <= 9998 ? $year : today()->year;
        $turnover = DocumentedTurnover::forYear($year);
        $withholding = WithholdingReview::forYear($year);
        $estimator = new TaxEstimator(app(FinanceSettings::class), $year);
        $threshold = $estimator->vatExempt() ? TerritorialTurnover::forYear($year)->threshold($estimator) : null;

        return [
            'summaryNotice' => [
                'title' => __('Simulation only'),
                'body' => __('Check with your accountant before making a decision.'),
            ],
            'summary' => [
                [
                    'label' => __('Documented invoicing in :year', ['year' => $year]),
                    'value' => $turnover->billedNet === null ? '—' : Money::format($turnover->billedNet, locale: app()->getLocale()),
                    'note' => $this->result !== null && $this->result['year'] === $year && $this->result['scenarioBilling'] !== null
                        ? __('+:amount with the retainer', ['amount' => Money::format($this->result['retainerBilling'], locale: app()->getLocale())])
                        : __('Excluding VAT, by document date'),
                ],
                [
                    'label' => __('Withheld at source in :year', ['year' => $year]),
                    'value' => $withholding->retainedTotal === null ? '—' : Money::format($withholding->retainedTotal, locale: app()->getLocale()),
                    'note' => __('Records with verified source'),
                ],
                ...($threshold === null ? [] : [[
                    'label' => __('VAT exemption threshold'),
                    'value' => Number::format($threshold['percent'], precision: 1, locale: app()->getLocale()).'%',
                    'percent' => $threshold['percent'],
                    'note' => $threshold['detail'],
                    'valueTone' => $threshold['status'] === 'ok' ? null : $threshold['status'],
                ]]),
            ],
        ];
    }

    public function updatedData(): void
    {
        $this->result = null;
    }

    public function compare(): void
    {
        $this->result = null;
        $data = $this->form->getState();

        $this->result = IrsScenario::compare(
            year: (int) $data['year'],
            monthlyNetCents: $data['monthlyNet'],
            months: (int) $data['months'],
            scenarioRetentionCents: $data['scenarioRetention'],
        );
        $this->result['settlement'] = null;
        $this->result['settlementToConfirm'] = null;

        if ((int) $data['year'] !== IrsSettlementEstimate::YEAR) {
            return;
        }

        $residence = self::residence($data['residence'] ?? null);
        $this->result['settlementResidence'] = $residence->label();
        $this->result['settlementToConfirm'] = IrsSettlementEstimate::toConfirm($residence);

        if ($this->result['settlementToConfirm'] === null && ($data['estimateSettlement'] ?? false)) {
            $this->result['settlement'] = [
                'baseline' => $this->calculateSettlement($data, 'baseline', $residence),
                'combined' => $this->calculateSettlement($data, 'combined', $residence),
            ];
        }
    }

    /** The fiscal residence chosen on the page; the mainland when none is chosen. */
    private static function residence(mixed $value): PortugueseVatRegion
    {
        return PortugueseVatRegion::tryFrom((string) $value) ?? PortugueseVatRegion::Mainland;
    }

    /** @return list<TextInput> */
    private function settlementInputs(string $prefix): array
    {
        $estimatesSettlement = fn (Get $get): bool => (bool) $get('estimateSettlement')
            && IrsSettlementEstimate::toConfirm(self::residence($get('residence'))) === null;

        return array_map(fn (TextInput $input): TextInput => $input
            ->dehydrated($estimatesSettlement)
            ->validatedWhenNotDehydrated(false)
            ->required(), [
                $this->moneyInput($prefix.'Taxable')->label(__('Taxable income'))
                    ->helperText(__('Annual amount after applicable coefficients, specific deductions and allowances, including the minimum subsistence allowance where applicable.')),
                $this->moneyInput($prefix.'Deductions')->label(__('Applicable tax credits'))
                    ->helperText(__('Enter the total after applying the statutory limits.')),
                $this->moneyInput($prefix.'TaxWithholding')->label(__('Total personal income tax withheld'))
                    ->helperText(__('Annual total for this scenario, including other sources where applicable. Enter 0 if there is no withholding.')),
                $this->moneyInput($prefix.'AdvancePayments')->label(__('Advance tax payments'))
                    ->helperText(__('Enter the annual total or 0.')),
            ]);
    }

    /** @return array{grossTax: int, solidarity: int, taxAfterDeductions: int, payable: int, refund: int} */
    private function calculateSettlement(array $data, string $prefix, PortugueseVatRegion $residence): array
    {
        return IrsSettlementEstimate::calculate(
            (int) $data['year'],
            $data[$prefix.'Taxable'],
            $data[$prefix.'Deductions'],
            $data[$prefix.'TaxWithholding'],
            $data[$prefix.'AdvancePayments'],
            $residence,
        );
    }

    private function moneyInput(string $name): TextInput
    {
        return TextInput::make($name)
            ->inputMode('decimal')->suffix('€')
            ->stripCharacters([' ', "\u{00A0}", "\u{202F}"])
            ->regex('/\A[0-9]{1,9}(?:[,.][0-9]{1,2})?\z/')
            ->validationMessages(['regex' => __('Enter an amount in euros with up to two decimal places.')])
            ->dehydrateStateUsing(fn (?string $state): ?int => Money::toCents($state));
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return __('Personal income tax scenario');
    }

    #[Unexposed]
    public static function getNavigationGroup(): string
    {
        return __('Taxes');
    }

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('Personal income tax scenario');
    }
}
