<?php

namespace App\Filament\Pages;

use App\Filament\Analysis\Widgets\TaxQuartersTable;
use App\Support\FinanceSettings;
use App\Support\Money;
use App\Support\Period;
use App\Support\PeriodSummary;
use App\Support\TaxEstimator;
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

    protected static string|UnitEnum|null $navigationGroup = 'Análise';

    protected static ?int $navigationSort = 3;

    #[Url]
    public ?int $year = null;

    public function mount(): void
    {
        $this->year ??= today()->year;
    }

    public function getSubheading(): string
    {
        return 'ENI em regime simplificado. O rendimento considerado é o que recebeste no ano.';
    }

    protected function getHeaderActions(): array
    {
        return [
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

        return $schema->components([
            ...($estimator->settings()->isIncomplete() ? [self::incompleteSettingsCallout()] : []),
            Callout::make('Estimativa — confirma os valores com o teu contabilista.')
                ->description('Regras simplificadas: Segurança Social sobre 70% do rendimento de cada trimestre, IRS pelo coeficiente do regime simplificado e o limite de isenção de IVA, segundo as Definições fiscais.')
                ->icon(Phosphor::WarningCircle)
                ->status('warning'),
            Section::make()
                ->columns(['md' => 4])
                ->schema([
                    Select::make('year')
                        ->label('Ano')
                        ->options(PeriodSummary::availableYears())
                        ->selectablePlaceholder(false)
                        ->native(false)
                        ->live(),
                ]),
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
                    ->schema($estimator->vatExempt()
                        ? [
                            TextEntry::make('vat')->hiddenLabel()->state(number_format($estimator->vatThresholdPercent(), 1, ',', ' ').'%')->size(TextSize::Large)->weight('bold')
                                ->color(match ($estimator->vatThresholdStatus()) {
                                    'danger' => 'danger',
                                    'warning' => 'warning',
                                    default => 'success',
                                }),
                            TextEntry::make('vat_note')->hiddenLabel()->color('gray')
                                ->state('Limite de isenção de IVA: '.Money::format($estimator->income()).' de '.Money::format($estimator->settings()->vatExemptionThreshold()).'.'),
                        ]
                        : [
                            TextEntry::make('vat')->hiddenLabel()->state(Money::format($estimator->vatBalance()))->size(TextSize::Large)->weight('bold'),
                            TextEntry::make('vat_note')->hiddenLabel()->color('gray')
                                ->state('IVA liquidado '.Money::format($estimator->vatCollected()).' − IVA dedutível '.Money::format($estimator->vatPaid()).'.'),
                        ]),
            ]),
            ...match (true) {
                $estimator->vatExempt() && $estimator->vatThresholdStatus() === 'danger' => [
                    Callout::make('Ultrapassaste o limite de isenção de IVA')
                        ->description('Fala com o teu contabilista: pode ser preciso passar ao regime normal de IVA.')
                        ->icon(Phosphor::WarningCircle)
                        ->status('danger'),
                ],
                $estimator->vatExempt() && $estimator->vatThresholdStatus() === 'warning' => [
                    Callout::make('Estás perto do limite de isenção de IVA')
                        ->description('Já usaste '.number_format($estimator->vatThresholdPercent(), 1, ',', ' ').'% do limite anual.')
                        ->icon(Phosphor::WarningCircle)
                        ->status('warning'),
                ],
                default => [],
            },
            ...$this->getWidgetsSchemaComponents([TaxQuartersTable::class], ['year' => $this->year]),
        ]);
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
                .($reduction > 0
                    ? ' ('.self::decimal($estimator->settings()->irsCoefficient()).' com redução de '.self::percent($reduction).' — '.($reduction === 0.5 ? '1.º' : '2.º').' ano)'
                    : '')),
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

    /** Why the year's contribution is lower: an exemption, or months before the obligation starts. */
    private function socialSecurityNote(TaxEstimator $estimator): string
    {
        $startsOn = $estimator->ssObligationStartsOn();

        return match (true) {
            // An exemption covers every month alike.
            $estimator->ssMonthStatus(CarbonImmutable::create($estimator->year, 1, 1)) === 'exempt' => 'Isento (art. 157.º).',
            // The year's quarters are paid from April on; an earlier start leaves none of them exempt.
            $startsOn !== null && $startsOn->gt(CarbonImmutable::create($estimator->year, 4, 1)) => 'Isento (1.º enquadramento) até '
                .mb_strtolower(Period::MONTHS[$startsOn->subMonth()->month - 1]).' de '.$startsOn->subMonth()->year
                .($estimator->ssObligationIsEstimated() ? ' (estimativa).' : '.'),
            default => 'Estimativa do ano, pelos trimestres abaixo.',
        };
    }
}
