<?php

namespace App\Filament\Pages;

use App\Enums\Language;
use App\Filament\Analysis\Widgets\TaxQuartersTable;
use App\Filament\Pages\Concerns\HasTaxSubNavigation;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Widgets\TaxObligationsCard;
use App\Support\DocumentedTurnover;
use App\Support\FinanceSettings;
use App\Support\Money;
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
use Illuminate\Support\Number;
use Livewire\Attributes\Url;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * "Estimativa de impostos" for an ENI in the simplified regime. Estimates only.
 */
class TaxEstimate extends Page
{
    use HasTaxSubNavigation;

    protected static ?string $slug = 'estimativa-de-impostos';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::Calculator;

    protected static ?int $navigationSort = 3;

    #[Url]
    public ?int $year = null;

    public function mount(): void
    {
        $this->year ??= today()->year;
    }

    public function getSubheading(): string
    {
        return __('Sole trader under the simplified regime. The estimate uses income received during the year.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('socialSecurity')
                ->label(__('Social Security'))
                ->color('gray')
                ->url(SocialSecurity::getUrl()),
            Action::make('vatReview')
                ->label(__('VAT review'))
                ->color('gray')
                ->url(fn (): string => VatReviewPage::getUrl(['year' => $this->year])),
            Action::make('settings')
                ->label(__('Tax settings'))
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
            Callout::make(__('Estimate — check the amounts with your accountant.'))
                ->description(__('See the basis for each amount and the domestic turnover coverage.'))
                ->icon(Phosphor::WarningCircle)
                ->status('warning'),
            Section::make()
                ->columns(['md' => 4])
                ->schema([
                    Select::make('year')
                        ->label(__('Year'))
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
                Section::make(__('Earnings'))
                    ->icon(Phosphor::HandCoins)
                    ->schema([
                        TextEntry::make('income')->hiddenLabel()->state(Money::format($estimator->income(), locale: app()->getLocale()))->size(TextSize::Large)->weight('bold'),
                        TextEntry::make('income_note')->hiddenLabel()
                            ->state(__('Received in :year (used as a proxy for relevant income).', ['year' => $estimator->year]))
                            ->color('gray'),
                        TextEntry::make('exempt_note')
                            ->hiddenLabel()
                            ->state($estimator->exemptIncome() > 0
                                ? __('Excluded :amount in tax-exempt payments received.', ['amount' => Money::format($estimator->exemptIncome(), locale: app()->getLocale())])
                                : __('There are no tax-exempt payments received. Mark them in Payments received or on the retainer.'))
                            ->icon(Phosphor::ShieldCheck)
                            ->color($estimator->exemptIncome() > 0 ? 'info' : 'gray'),
                    ]),
                Section::make(__('Social Security'))
                    ->icon(Phosphor::Bank)
                    ->schema([
                        TextEntry::make('ss')->hiddenLabel()->state(Money::format($estimator->ssYearTotal(), locale: app()->getLocale()))->size(TextSize::Large)->weight('bold'),
                        TextEntry::make('ss_note')->hiddenLabel()->color('gray')->state(fn (): string => $this->socialSecurityNote($estimator)),
                    ]),
                Section::make(__('Personal income tax'))
                    ->icon(Phosphor::Receipt)
                    ->schema($this->irsEntries($estimator)),
                Section::make(__('VAT'))
                    ->icon(Phosphor::Percent)
                    ->schema($this->vatEntries($estimator, $vatThreshold, $territorial->subtotal() !== null)),
            ]),
            ...(($vatThreshold['notice'] ?? null) !== null ? [
                Callout::make(__('VAT threshold exceeded'))->description($vatThreshold['notice'])->icon(Phosphor::WarningCircle)->status('danger'),
            ] : []),
            ...(($vatThreshold['advisory'] ?? null) !== null ? [
                Callout::make(__('Indicative VAT warning'))->description($vatThreshold['advisory'])->icon(Phosphor::WarningCircle)->status('warning'),
            ] : []),
            ...($previousYearNotice === null ? [] : [
                Callout::make(__('VAT threshold exceeded in the previous year'))->description($previousYearNotice)->icon(Phosphor::WarningCircle)->status('warning'),
            ]),
            ...$this->getWidgetsSchemaComponents([TaxQuartersTable::class], ['year' => $this->year]),
            // The month's obligations and what comes next only make sense for the running year.
            ...($estimator->year === today()->year ? $this->getWidgetsSchemaComponents([TaxObligationsCard::class]) : []),
        ]);
    }

    private function turnoverSection(DocumentedTurnover $turnover, TerritorialTurnover $territorial): Section
    {
        $year = $territorial->year;
        $subtotal = $territorial->subtotal();
        $billingNote = __('Operational indicator based on issue date. Credit notes reduce the total.');

        if ($turnover->nonEuroDocumentCount > 0) {
            $billingNote .= ' '.trans_choice(
                'Excluded :count document in another currency from the EUR total.|Excluded :count documents in other currencies from the EUR total.',
                $turnover->nonEuroDocumentCount,
            );
        }

        return Section::make(__('Invoicing and payments received'))
            ->columns(['md' => 3])
            ->headerActions([
                Action::make('billedDocuments')->label(__('View included documents'))->link()
                    ->visible(fn (): bool => $turnover->billedNet !== null && FiscalDocumentResource::canViewAny())
                    ->url(fn (): string => FiscalDocumentResource::getUrl('index', ['tableFilters' => ['billed_year' => ['value' => $year]]])),
                Action::make('territorialTurnover')->label(__('Review domestic turnover'))->link()
                    ->visible(fn (): bool => TerritorialTurnoverPage::canAccess())
                    ->url(fn (): string => TerritorialTurnoverPage::getUrl(['year' => $year])),
            ])
            ->schema([
                TextEntry::make('billed_net')
                    ->label(__('Documented net invoicing'))
                    ->state($turnover->billedNet === null ? __('No documents in EUR') : Money::format($turnover->billedNet, locale: app()->getLocale()))
                    ->size(TextSize::Large)->weight('bold')
                    ->helperText($billingNote),
                TextEntry::make('registered_receipts')
                    ->label(__('Recorded payments received'))
                    ->state(Money::format($turnover->received, locale: app()->getLocale()))
                    ->size(TextSize::Large)->weight('bold')
                    ->helperText(__('Total by payment date, including payments marked as exempt.')),
                TextEntry::make('territorial_turnover')
                    ->label(__('Domestic turnover'))
                    ->state($subtotal === null ? __('Coverage unavailable') : Money::format($subtotal, locale: app()->getLocale()))
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
                ->state(self::thresholdPercent($threshold['percent']))
                ->size(TextSize::Large)->weight('bold')
                ->color($threshold['status'] === 'ok' ? 'gray' : $threshold['status']);
            $label = $threshold['usesReceipts'] ? __('VAT exemption threshold · approximate based on payments received') : $threshold['label'];
            $entries[] = TextEntry::make('vat_note')->hiddenLabel()->color('gray')
                ->state($label.'. '.$threshold['detail']);
        }

        $mixed = count($periods) > 1;
        $showPeriodProxy = $mixed && ! $hasTerritorialSubtotal;
        $dateFormat = __('m/d');

        foreach ($periods as $index => $period) {
            $range = CarbonImmutable::parse($period['from'])->format($dateFormat).' – '.CarbonImmutable::parse($period['to'])->format($dateFormat);
            $basis = $this->vatBasisLabel(
                $mixed ? $period['basis'] : $estimator->fiscalBasisOn(),
                includeThreshold: $period['exempt'] && ($showPeriodProxy || ($threshold['usesReceipts'] ?? false)),
                explainSettings: $mixed || $estimator->year !== today()->year,
            );

            if ($period['exempt']) {
                $detail = match (true) {
                    $showPeriodProxy => __('VAT exemption · approximate based on payments received through the end of the period. :percent of :threshold.', [
                        'percent' => self::thresholdPercent($period['thresholdPercent']),
                        'threshold' => Money::format($period['threshold'], locale: app()->getLocale()),
                    ]),
                    $mixed => __('VAT exemption.'),
                    default => '',
                };
            } else {
                $detail = __('Output VAT :collected − deductible VAT :paid.', [
                    'collected' => Money::format($period['collected'], locale: app()->getLocale()),
                    'paid' => Money::format($period['paid'], locale: app()->getLocale()),
                ]);

                if ($mixed) {
                    $detail = __('Standard VAT regime · estimated balance :balance · :detail', [
                        'balance' => Money::format($period['balance'], locale: app()->getLocale()),
                        'detail' => $detail,
                    ]);
                } else {
                    $entries[] = TextEntry::make('vat_balance')->hiddenLabel()
                        ->state(Money::format($period['balance'], locale: app()->getLocale()))->size(TextSize::Large)->weight('bold');
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
            return $explainSettings ? __('Basis for the tax status: current tax settings.') : '';
        }

        return __('Tax profile in effect since :date.', [
            'date' => CarbonImmutable::parse($basis['effective_from'])->format(Language::resolve(app()->getLocale())->dateFormat()),
        ])
            .($confirmed < count($relevant) ? __(' Missing fields use current tax settings.') : '');
    }

    /** Shown on the Estimativa and the Previsão de tesouraria until the start of activity is filled in. */
    public static function incompleteSettingsCallout(): Callout
    {
        return Callout::make(__('Incomplete tax settings: approximate amounts'))
            ->description(__('Enter when you started trading and whether this was your first registration or a restart. Without this, Social Security and personal income tax figures are approximate.'))
            ->icon(Phosphor::WarningCircle)
            ->status('warning')
            ->actions([
                Action::make('completeSettings')
                    ->label(__('Open tax settings'))
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
            TextEntry::make('irs')->hiddenLabel()->state(Money::format($estimator->irsTaxableIncome(), locale: app()->getLocale()))->size(TextSize::Large)->weight('bold'),
            $line('irs_note', __('Taxable income. The tax depends on the personal income tax tables and is not calculated here.')),
            $line('irs_gross', __('Gross income: :amount', ['amount' => Money::format($estimator->income(), locale: app()->getLocale())])),
            $estimator->settings()->irsJovem() ? $line('irs_jovem', $jovemPercent > 0
                ? __('Exempt under IRS Jovem (:percent, year :year): −:amount', [
                    'percent' => self::percent($jovemPercent),
                    'year' => $estimator->settings()->irsJovemYearNumber($estimator->year),
                    'amount' => Money::format($jovemExempt, locale: app()->getLocale()),
                ])
                    .($jovemExempt === TaxEstimator::IRS_JOVEM_IAS_CAP * $estimator->ias()
                        ? __(' (cap of :cap × IAS:provisional)', [
                            'cap' => TaxEstimator::IRS_JOVEM_IAS_CAP,
                            'provisional' => $estimator->iasIsProvisional() ? __(', provisional') : '',
                        ])
                        : '')
                : __('IRS Jovem does not apply in :year (age or year of eligibility).', ['year' => $estimator->year])) : null,
            $line('irs_coefficient', __('Coefficient :coefficient', ['coefficient' => self::decimal($estimator->effectiveIrsCoefficient())])
                .match (true) {
                    $reduction > 0 => __(' (:base reduced by :percent — :year)', [
                        'base' => self::decimal($estimator->settings()->irsCoefficient()),
                        'percent' => self::percent($reduction),
                        'year' => $reduction === 0.5 ? __('1st year') : __('2nd year'),
                    ]),
                    $estimator->irsCoefficientReductionBlocker() === 'dependent_income' => __(' (no reduction: year with a salary or pension)'),
                    $estimator->irsCoefficientReductionBlocker() === 'recent_restart' => __(' (no reduction: restarted less than five years after ceasing activity)'),
                    default => '',
                }),
            $jovemPercent > 0 && $reduction > 0
                ? $line('irs_interpretation', __('Combining IRS Jovem with the reduction for the first years is an interpretation of Articles 12-B and 31 of the Portuguese Income Tax Code, without a binding ruling from the Tax Authority.'))
                : null,
        ]));
    }

    private static function thresholdPercent(float $value): string
    {
        $formatted = Number::format(round($value, 1), precision: 1, locale: app()->getLocale());

        return str_replace(["\u{00a0}", "\u{202f}"], ' ', $formatted).'%';
    }

    private static function decimal(float $value): string
    {
        return Number::format(round($value, 4), maxPrecision: 4, locale: app()->getLocale());
    }

    /** "25 %". */
    private static function percent(float $share): string
    {
        return __(':percent%', ['percent' => self::decimal($share * 100)]);
    }

    /**
     * Why the year's contribution is lower: an exemption, or months before the obligation starts.
     * The first contribution is the start month's, paid the month after.
     */
    private function socialSecurityNote(TaxEstimator $estimator): string
    {
        $startsOn = $estimator->ssObligationStartsOn()?->locale(app()->getLocale());

        return match (true) {
            // An exemption covers every month alike.
            $estimator->ssMonthStatus(CarbonImmutable::create($estimator->year, 1, 1)) === 'exempt' => __('Exempt (Article 157).'),
            // The year's income sets the contributions from April on; an earlier start leaves none of them exempt.
            $startsOn !== null && $startsOn->gt(CarbonImmutable::create($estimator->year, 4, 1)) => __('Exempt (first enrollment) until :month :year:estimate. The first contribution, for :first, is due in :due.', [
                'month' => $startsOn->subMonth()->translatedFormat('F'),
                'year' => $startsOn->subMonth()->year,
                'estimate' => $estimator->ssObligationIsEstimated() ? __(' (estimate)') : '',
                'first' => $startsOn->translatedFormat('F'),
                'due' => $startsOn->addMonth()->translatedFormat('F'),
            ]),
            default => __('Annual estimate based on the quarters below.'),
        };
    }

    public function getTitle(): string
    {
        return __('Tax estimate');
    }

    public static function getNavigationGroup(): string
    {
        return __('Taxes');
    }

    public static function getNavigationLabel(): string
    {
        return __('Tax estimate');
    }
}
