<?php

namespace App\Filament\Pages;

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
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
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
        $this->form->fill(['year' => today()->year]);
    }

    public function hydrate(): void
    {
        abort_unless(static::canAccess() && $this->accountId === CurrentAccount::id(), 403);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make(__('Year and new retainer'))
                ->columns(['md' => 2])
                ->schema([
                    TextInput::make('year')->label(__('Comparison year'))
                        ->integer()->minValue(1900)->maxValue(9998)->required()
                        ->live(onBlur: true)
                        ->validationMessages([
                            'integer' => __('Enter a whole year.'),
                            'min' => __('Enter a year from 1900 onward.'),
                            'max' => __('Enter a year no later than 9998.'),
                        ]),
                    TextInput::make('months')->label(__('Months invoiced in the year'))
                        ->integer()->minValue(1)->maxValue(12)->required()
                        ->validationMessages([
                            'integer' => __('Enter a whole number of months.'),
                            'min' => __('Enter at least one month.'),
                            'max' => __('Enter no more than 12 months.'),
                        ]),
                    $this->moneyInput('monthlyNet')->label(__('Monthly amount excluding VAT'))
                        ->helperText(__('Monthly amount to invoice before withholding.'))
                        ->required()
                        ->rule('not_regex:/\A0+(?:[,.]0+)?\z/')
                        ->validationMessages([
                            'regex' => __('Enter an amount in euros with up to two decimal places.'),
                            'not_regex' => __('Enter a monthly amount greater than zero.'),
                        ]),
                    $this->moneyInput('scenarioRetention')->label(__('Withholding on the new retainer for the year'))
                        ->helperText(__('Optional. Enter the total for the selected months. Use €0 if that is the amount for this scenario.')),
                ]),
            Section::make(__('Personal income tax assessment estimate'))
                ->description(__('Available for 2026 income, with individual taxation at mainland general rates.'))
                ->visible(fn (Get $get): bool => (int) $get('year') === IrsSettlementEstimate::YEAR)
                ->schema([
                    Toggle::make('estimateSettlement')->label(__('Calculate with verified tax details'))->live(),
                    Section::make(__('Current scenario'))->columns(['md' => 2])->schema($this->settlementInputs('baseline'))
                        ->visible(fn (Get $get): bool => (bool) $get('estimateSettlement')),
                    Section::make(__('Scenario with the new retainer'))->columns(['md' => 2])->schema($this->settlementInputs('combined'))
                        ->visible(fn (Get $get): bool => (bool) $get('estimateSettlement')),
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
                    'tone' => $threshold['status'] === 'ok' ? null : $threshold['status'],
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

        if ((int) $data['year'] === IrsSettlementEstimate::YEAR && ($data['estimateSettlement'] ?? false)) {
            $this->result['settlement'] = [
                'baseline' => $this->calculateSettlement($data, 'baseline'),
                'combined' => $this->calculateSettlement($data, 'combined'),
            ];
        }
    }

    /** @return list<TextInput> */
    private function settlementInputs(string $prefix): array
    {
        return [
            $this->moneyInput($prefix.'Taxable')->label(__('Taxable income'))->required()
                ->helperText(__('Annual amount after applicable coefficients, specific deductions and allowances, including the minimum subsistence allowance where applicable.')),
            $this->moneyInput($prefix.'Deductions')->label(__('Applicable tax credits'))->required()
                ->helperText(__('Enter the total after applying the statutory limits.')),
            $this->moneyInput($prefix.'TaxWithholding')->label(__('Total personal income tax withheld'))->required()
                ->helperText(__('Annual total for this scenario, including other sources where applicable. Enter 0 if there is no withholding.')),
            $this->moneyInput($prefix.'AdvancePayments')->label(__('Advance tax payments'))->required()
                ->helperText(__('Enter the annual total or 0.')),
        ];
    }

    /** @return array{grossTax: int, solidarity: int, taxAfterDeductions: int, payable: int, refund: int} */
    private function calculateSettlement(array $data, string $prefix): array
    {
        return IrsSettlementEstimate::calculate(
            (int) $data['year'],
            $data[$prefix.'Taxable'],
            $data[$prefix.'Deductions'],
            $data[$prefix.'TaxWithholding'],
            $data[$prefix.'AdvancePayments'],
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
