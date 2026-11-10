<?php

namespace App\Filament\Pages;

use App\Filament\Analysis\Widgets\TaxQuartersTable;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Support\DocumentedTurnover;
use App\Support\FinanceSettings;
use App\Support\Money;
use App\Support\Period;
use App\Support\PeriodSummary;
use App\Support\TaxEstimator;
use App\Support\TerritorialTurnover;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Livewire\Attributes\Url;
use ToneGabes\Filament\Icons\Enums\Phosphor;
use UnitEnum;

/**
 * "Estimativa de impostos" for an ENI in the simplified regime. Estimates only.
 */
class TaxEstimate extends Page
{
    protected static ?string $title = 'Estimativa de impostos';

    protected static ?string $slug = 'estimativa-de-impostos';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::Calculator;

    protected static string|UnitEnum|null $navigationGroup = 'Impostos';

    protected static ?int $navigationSort = 3;

    #[Url]
    public ?int $year = null;

    public function mount(): void
    {
        $this->year ??= today()->year;
    }

    public function getSubheading(): string
    {
        return 'ENI em regime simplificado. A estimativa usa o rendimento recebido no ano.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('socialSecurity')
                ->label('Segurança Social')
                ->color('gray')
                ->url(SocialSecurity::getUrl()),
            Action::make('vatReview')
                ->label('Revisão de IVA')
                ->color('gray')
                ->url(fn (): string => VatReviewPage::getUrl(['year' => $this->year])),
            Action::make('settings')
                ->label('Definições fiscais')
                ->icon(Phosphor::Sliders)
                ->color('gray')
                ->url(FinanceSettingsPage::getUrl()),
        ];
    }

    public function content(Schema $schema): Schema
    {
        $estimator = new TaxEstimator(app(FinanceSettings::class), $this->year ?? today()->year);
        $territorial = TerritorialTurnover::forYear($estimator->year);
        $vatThreshold = $estimator->vatExempt() ? $territorial->threshold($estimator) : null;
        $previousYearNotice = $vatThreshold !== null && $estimator->year >= 2026
            ? TerritorialTurnover::forYear($estimator->year - 1)->nextYearNotice()
            : null;

        return $schema->components([
            ...($estimator->settings()->isIncomplete() ? [self::incompleteSettingsCallout()] : []),
            Callout::make('Estimativa — confirma os valores com o teu contabilista.')
                ->description('Consulta a base de cálculo de cada valor e a cobertura do volume de negócios nacional.')
                ->icon(Phosphor::WarningCircle)
                ->status('warning'),
            Section::make()
                ->columns(['md' => 4])
                ->schema([
                    Select::make('year')
                        ->label('Ano')
                        ->options(fn (): array => collect(array_keys(PeriodSummary::availableYears()))
                            ->merge(DocumentedTurnover::issueYears())
                            ->merge(TerritorialTurnover::reviewYears())
                            ->unique()->sortDesc()->mapWithKeys(fn (int $year) => [$year => (string) $year])->all())
                        ->selectablePlaceholder(false)
                        ->native(false)
                        ->live(),
                ]),
            $this->turnoverSection(DocumentedTurnover::forYear($estimator->year), $territorial),
            Grid::make(['md' => 2, 'xl' => 4])->schema([
                Section::make('Rendimento')
                    ->icon(Phosphor::HandCoins)
                    ->schema([
                        TextEntry::make('income')->hiddenLabel()->state(Money::format($estimator->income()))->size(TextSize::Large)->weight('bold'),
                        TextEntry::make('income_note')->hiddenLabel()->state('Recebido em '.$estimator->year.' (proxy do rendimento relevante).')->color('gray'),
                        TextEntry::make('exempt_note')
                            ->hiddenLabel()
                            ->state($estimator->exemptIncome() > 0
                                ? 'Excluídos '.Money::format($estimator->exemptIncome()).' de recebimentos isentos.'
                                : 'Nenhum recebimento isento de impostos — marca-os em Recebimentos ou na avença.')
                            ->icon(Phosphor::ShieldCheck)
                            ->color($estimator->exemptIncome() > 0 ? 'info' : 'gray'),
                    ]),
                Section::make('Segurança Social')
                    ->icon(Phosphor::Bank)
                    ->schema([
                        TextEntry::make('ss')->hiddenLabel()->state(Money::format($estimator->ssYearTotal()))->size(TextSize::Large)->weight('bold'),
                        TextEntry::make('ss_note')->hiddenLabel()->color('gray')->state(fn (): string => $this->socialSecurityNote($estimator)),
                    ]),
                Section::make('IRS')
                    ->icon(Phosphor::Receipt)
                    ->schema($this->irsEntries($estimator)),
                Section::make('IVA')
                    ->icon(Phosphor::Percent)
                    ->schema($this->vatEntries($estimator, $vatThreshold, $territorial->subtotal() !== null)),
            ]),
            ...(($vatThreshold['notice'] ?? null) !== null ? [
                Callout::make('Limiar de IVA ultrapassado')->description($vatThreshold['notice'])->icon(Phosphor::WarningCircle)->status('danger'),
            ] : []),
            ...(($vatThreshold['advisory'] ?? null) !== null ? [
                Callout::make('Alerta indicativo de IVA')->description($vatThreshold['advisory'])->icon(Phosphor::WarningCircle)->status('warning'),
            ] : []),
            ...($previousYearNotice === null ? [] : [
                Callout::make('Limiar de IVA ultrapassado no ano anterior')->description($previousYearNotice)->icon(Phosphor::WarningCircle)->status('warning'),
            ]),
            ...$this->getWidgetsSchemaComponents([TaxQuartersTable::class], ['year' => $this->year]),
        ]);
    }

    private function turnoverSection(DocumentedTurnover $turnover, TerritorialTurnover $territorial): Section
    {
        $year = $territorial->year;
        $subtotal = $territorial->subtotal();
        $billingNote = 'Indicador operacional pela data de emissão. As notas de crédito reduzem o total.';

        if ($turnover->nonEuroDocumentCount > 0) {
            $billingNote .= ' Documentos noutras moedas excluídos do total em EUR ('.$turnover->nonEuroDocumentCount.').';
        }

        return Section::make('Faturação e recebimentos')
            ->columns(['md' => 3])
            ->headerActions([
                Action::make('billedDocuments')->label('Ver documentos incluídos')->link()
                    ->visible(fn (): bool => $turnover->billedNet !== null && FiscalDocumentResource::canViewAny())
                    ->url(fn (): string => FiscalDocumentResource::getUrl('index', ['tableFilters' => ['billed_year' => ['value' => $year]]])),
                Action::make('territorialTurnover')->label('Rever volume nacional')->link()
                    ->visible(fn (): bool => TerritorialTurnoverPage::canAccess())
                    ->url(fn (): string => TerritorialTurnoverPage::getUrl(['year' => $year])),
            ])
            ->schema([
                TextEntry::make('billed_net')
                    ->label('Faturado líquido documentado')
                    ->state($turnover->billedNet === null ? 'Sem documentos em EUR' : Money::format($turnover->billedNet))
                    ->size(TextSize::Large)->weight('bold')
                    ->helperText($billingNote),
                TextEntry::make('registered_receipts')
                    ->label('Recebimentos registados')
                    ->state(Money::format($turnover->received))
                    ->size(TextSize::Large)->weight('bold')
                    ->helperText('Total pela data de pagamento, incluindo recebimentos marcados como isentos.'),
                TextEntry::make('territorial_turnover')
                    ->label('Volume de negócios em território nacional')
                    ->state($subtotal === null ? 'Cobertura indisponível' : Money::format($subtotal))
                    ->size(TextSize::Large)->weight('bold')
                    ->helperText(($subtotal !== null ? $territorial->subtotalLabel().'. ' : '').$territorial->summary()),
            ]);
    }

    /**
     * @param  array{label: string, amount: int, percent: float, status: string, detail: string, notice: ?string, advisory: ?string, usesReceipts: bool}|null  $threshold
     * @return list<TextEntry>
     */
    private function vatEntries(TaxEstimator $estimator, ?array $threshold, bool $hasTerritorialSubtotal): array
    {
        $periods = $estimator->vatPeriods();
        $entries = [];

        if ($threshold !== null) {
            $entries[] = TextEntry::make('vat')->hiddenLabel()
                ->state(number_format($threshold['percent'], 1, ',', ' ').'%')
                ->size(TextSize::Large)->weight('bold')
                ->color($threshold['status'] === 'ok' ? 'gray' : $threshold['status']);
            $label = $threshold['usesReceipts'] ? 'Limite de isenção de IVA · aproximação por recebimentos' : $threshold['label'];
            $entries[] = TextEntry::make('vat_note')->hiddenLabel()->color('gray')
                ->state($label.'. '.$threshold['detail']);
        }

        $mixed = count($periods) > 1;
        $showPeriodProxy = $mixed && ! $hasTerritorialSubtotal;

        foreach ($periods as $index => $period) {
            $range = CarbonImmutable::parse($period['from'])->format('d/m').' – '.CarbonImmutable::parse($period['to'])->format('d/m');
            $basis = $this->vatBasisLabel(
                $mixed ? $period['basis'] : $estimator->fiscalBasisOn(),
                includeThreshold: $period['exempt'] && ($showPeriodProxy || ($threshold['usesReceipts'] ?? false)),
                explainSettings: $mixed || $estimator->year !== today()->year,
            );

            if ($period['exempt']) {
                $detail = match (true) {
                    $showPeriodProxy => 'Isenção de IVA · aproximação por recebimentos até ao fim do período. '
                        .number_format($period['thresholdPercent'], 1, ',', ' ').'% de '.Money::format($period['threshold']).'.',
                    $mixed => 'Isenção de IVA.',
                    default => '',
                };
            } else {
                $detail = 'IVA liquidado '.Money::format($period['collected']).' − IVA dedutível '.Money::format($period['paid']).'.';

                if ($mixed) {
                    $detail = 'Regime normal · saldo estimado '.Money::format($period['balance']).' · '.$detail;
                } else {
                    $entries[] = TextEntry::make('vat_balance')->hiddenLabel()
                        ->state(Money::format($period['balance']))->size(TextSize::Large)->weight('bold');
                }
            }

            if (($line = trim($detail.' '.$basis)) !== '') {
                $entries[] = TextEntry::make('vat_period_'.$index)->label($range)->hiddenLabel(! $mixed)->color('gray')->state($line);
            }
        }

        return $entries;
    }

    /** @param array{effective_from: ?string, confirmed_fields: list<string>} $basis */
    private function vatBasisLabel(array $basis, bool $includeThreshold, bool $explainSettings): string
    {
        $relevant = $includeThreshold ? ['vat_exempt', 'vat_exemption_threshold'] : ['vat_exempt'];
        $confirmed = count(array_intersect($relevant, $basis['confirmed_fields']));

        if ($confirmed === 0) {
            return $explainSettings ? 'Base do enquadramento: definições fiscais atuais.' : '';
        }

        return 'Perfil fiscal em vigor desde '.CarbonImmutable::parse($basis['effective_from'])->format('d/m/Y').'.'
            .($confirmed < count($relevant) ? ' Campos em falta: definições fiscais atuais.' : '');
    }

    /** Shown on the Estimativa and the Previsão de tesouraria until the start of activity is filled in. */
    public static function incompleteSettingsCallout(): Callout
    {
        return Callout::make('Definições fiscais incompletas: valores aproximados')
            ->description('Falta dizer quando começaste a atividade e se foi o primeiro início ou um reinício. Sem isso, a Segurança Social e o IRS são aproximados.')
            ->icon(Phosphor::WarningCircle)
            ->status('warning')
            ->actions([
                Action::make('completeSettings')
                    ->label('Abrir Definições fiscais')
                    ->link()
                    ->url(FinanceSettingsPage::getUrl()),
            ]);
    }

    /**
     * Taxable income, then each step: gross, IRS Jovem, coefficient used and why.
     *
     * @return array<int, TextEntry>
     */
    private function irsEntries(TaxEstimator $estimator): array
    {
        $line = fn (string $name, string $state) => TextEntry::make($name)->hiddenLabel()->state($state)->color('gray');
        $jovemPercent = $estimator->irsJovemPercent();
        $jovemExempt = $estimator->irsJovemExempt();
        $reduction = $estimator->irsCoefficientReduction();

        return array_values(array_filter([
            TextEntry::make('irs')->hiddenLabel()->state(Money::format($estimator->irsTaxableIncome()))->size(TextSize::Large)->weight('bold'),
            $line('irs_note', 'Rendimento tributável. O imposto depende das tabelas de IRS e não é calculado.'),
            $line('irs_gross', 'Rendimento bruto: '.Money::format($estimator->income())),
            $estimator->settings()->irsJovem() ? $line('irs_jovem', $jovemPercent > 0
                ? 'Isento pelo IRS Jovem ('.self::percent($jovemPercent).', ano n.º '.$estimator->settings()->irsJovemYearNumber($estimator->year).'): −'.Money::format($jovemExempt)
                    .($jovemExempt === TaxEstimator::IRS_JOVEM_IAS_CAP * $estimator->ias()
                        ? ' (limite de '.TaxEstimator::IRS_JOVEM_IAS_CAP.' × IAS'.($estimator->iasIsProvisional() ? ', provisório' : '').')'
                        : '')
                : 'IRS Jovem: não se aplica em '.$estimator->year.' (idade ou ano n.º).') : null,
            $line('irs_coefficient', 'Coeficiente '.self::decimal($estimator->effectiveIrsCoefficient())
                .match (true) {
                    $reduction > 0 => ' ('.self::decimal($estimator->settings()->irsCoefficient()).' com redução de '.self::percent($reduction).' — '.($reduction === 0.5 ? '1.º' : '2.º').' ano)',
                    $estimator->irsCoefficientReductionBlocker() === 'dependent_income' => ' (sem redução: ano com salário ou pensão)',
                    $estimator->irsCoefficientReductionBlocker() === 'recent_restart' => ' (sem redução: reinício a menos de 5 anos da cessação)',
                    default => '',
                }),
            $jovemPercent > 0 && $reduction > 0
                ? $line('irs_interpretation', 'Somar o IRS Jovem à redução dos primeiros anos é uma interpretação dos art. 12.º-B e 31.º do CIRS, sem informação vinculativa da AT.')
                : null,
        ]));
    }

    /** "0,5625", "0,75". */
    private static function decimal(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, ',', ''), '0'), ',');
    }

    /** "25 %". */
    private static function percent(float $share): string
    {
        return self::decimal($share * 100).' %';
    }

    /**
     * Why the year's contribution is lower: an exemption, or months before the obligation starts.
     * The first contribution is the start month's, paid the month after.
     */
    private function socialSecurityNote(TaxEstimator $estimator): string
    {
        $startsOn = $estimator->ssObligationStartsOn();

        return match (true) {
            // An exemption covers every month alike.
            $estimator->ssMonthStatus(CarbonImmutable::create($estimator->year, 1, 1)) === 'exempt' => 'Isento (art. 157.º).',
            // The year's income sets the contributions from April on; an earlier start leaves none of them exempt.
            $startsOn !== null && $startsOn->gt(CarbonImmutable::create($estimator->year, 4, 1)) => 'Isento (1.º enquadramento) até '
                .mb_strtolower(Period::MONTHS[$startsOn->subMonth()->month - 1]).' de '.$startsOn->subMonth()->year
                .($estimator->ssObligationIsEstimated() ? ' (estimativa).' : '.')
                .' A primeira contribuição, de '.mb_strtolower(Period::MONTHS[$startsOn->month - 1])
                .', paga-se em '.mb_strtolower(Period::MONTHS[$startsOn->addMonth()->month - 1]).'.',
            default => 'Estimativa do ano, pelos trimestres abaixo.',
        };
    }
}
