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
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use ToneGabes\Filament\Icons\Enums\Phosphor;
use UnitEnum;

class IrsScenarioPage extends Page
{
    use HasTaxSubNavigation;

    protected static ?string $title = 'Cenário de IRS';

    protected static ?string $slug = 'cenario-irs';

    protected static string|UnitEnum|null $navigationGroup = 'Impostos';

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
            Section::make('Ano e nova avença')
                ->columns(['md' => 2])
                ->schema([
                    TextInput::make('year')->label('Ano da comparação')
                        ->integer()->minValue(1900)->maxValue(9998)->required()
                        ->live(onBlur: true)
                        ->validationMessages([
                            'integer' => 'Introduz um ano inteiro.',
                            'min' => 'Indica um ano a partir de 1900.',
                            'max' => 'Indica um ano até 9998.',
                        ]),
                    TextInput::make('months')->label('Meses faturados no ano')
                        ->integer()->minValue(1)->maxValue(12)->required()
                        ->validationMessages([
                            'integer' => 'Introduz um número inteiro de meses.',
                            'min' => 'Indica pelo menos um mês.',
                            'max' => 'Indica no máximo 12 meses.',
                        ]),
                    $this->moneyInput('monthlyNet')->label('Valor mensal sem IVA')
                        ->helperText('Valor a faturar por mês, antes de retenção.')
                        ->required()
                        ->rule('not_regex:/\A0+(?:[,.]0+)?\z/')
                        ->validationMessages([
                            'regex' => 'Introduz um valor em euros com até duas casas decimais.',
                            'not_regex' => 'Indica um valor mensal superior a zero.',
                        ]),
                    $this->moneyInput('scenarioRetention')->label('Retenção da nova avença no ano')
                        ->helperText('Opcional. Indica o total para os meses escolhidos. Usa 0 € se for esse o valor do cenário.'),
                ]),
            Section::make('Estimativa de liquidação de IRS')
                ->description('Disponível para rendimentos de 2026, em tributação individual pelas taxas gerais do continente.')
                ->visible(fn (Get $get): bool => (int) $get('year') === IrsSettlementEstimate::YEAR)
                ->schema([
                    Toggle::make('estimateSettlement')->label('Calcular com dados fiscais conferidos')->live(),
                    Section::make('Cenário atual')->columns(['md' => 2])->schema($this->settlementInputs('baseline'))
                        ->visible(fn (Get $get): bool => (bool) $get('estimateSettlement')),
                    Section::make('Cenário com a nova avença')->columns(['md' => 2])->schema($this->settlementInputs('combined'))
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
                'title' => 'Simulação: não é um cálculo oficial',
                'body' => 'Confirma com o teu contabilista antes de decidires.',
            ],
            'summary' => [
                [
                    'label' => 'Faturado documentado em '.$year,
                    'value' => $turnover->billedNet === null ? '—' : Money::format($turnover->billedNet),
                    'note' => $this->result !== null && $this->result['year'] === $year && $this->result['scenarioBilling'] !== null
                        ? '+'.Money::format($this->result['retainerBilling']).' com a avença'
                        : 'Sem IVA, pela data do documento',
                ],
                [
                    'label' => 'Retido na fonte em '.$year,
                    'value' => $withholding->retainedTotal === null ? '—' : Money::format($withholding->retainedTotal),
                    'note' => 'Registos com fonte conferida',
                ],
                ...($threshold === null ? [] : [[
                    'label' => 'Limite de isenção de IVA',
                    'value' => number_format($threshold['percent'], 1, ',', ' ').'%',
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
            $this->moneyInput($prefix.'Taxable')->label('Rendimento coletável')->required()
                ->helperText('Valor anual depois dos coeficientes, deduções específicas e abatimentos aplicáveis, incluindo mínimo de existência quando devido.'),
            $this->moneyInput($prefix.'Deductions')->label('Deduções à coleta aplicáveis')->required()
                ->helperText('Indica o total já sujeito aos limites legais.'),
            $this->moneyInput($prefix.'TaxWithholding')->label('Total de retenções por conta do IRS')->required()
                ->helperText('Total do ano para este cenário, incluindo outras fontes se aplicável. Indica 0 se não houver retenção.'),
            $this->moneyInput($prefix.'AdvancePayments')->label('Pagamentos por conta')->required()
                ->helperText('Indica o total anual ou 0.'),
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
            ->validationMessages(['regex' => 'Introduz um valor em euros com até duas casas decimais.'])
            ->dehydrateStateUsing(fn (?string $state): ?int => Money::toCents($state));
    }
}
