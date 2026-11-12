<?php

namespace App\Filament\Pages;

use App\Enums\Language;
use App\Filament\Analysis\Widgets\TaxQuartersTable;
use App\Filament\Pages\Concerns\HasTaxSubNavigation;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Support\PeriodPicker;
use App\Filament\Widgets\UpcomingTaxPayments;
use App\Support\DocumentedTurnover;
use App\Support\FinanceSettings;
use App\Support\Money;
use App\Support\PeriodSummary;
use App\Support\TaxEstimator;
use App\Support\TaxObligation;
use App\Support\TaxObligations;
use App\Support\TerritorialTurnover;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
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

    /** As in the mock: the year to show, then the way to the tax settings. The tabs lead to the other tax pages. */
    protected function getHeaderActions(): array
    {
        return [
            PeriodPicker::make('year', $this->yearOptions(), $this->year, fn (int|string $year) => $this->year = (int) $year, __('Year')),
            Action::make('settings')
                ->label(__('Tax settings'))
                ->color('gray')
                ->url(FinanceSettingsPage::getUrl()),
        ];
    }

    /** @return array<int, string> */
    private function yearOptions(): array
    {
        return collect(array_keys(PeriodSummary::availableYears()))
            ->merge(DocumentedTurnover::issueYears())
            ->merge(TerritorialTurnover::reviewYears())
            ->push(today()->year, $this->year ?? today()->year)
            ->unique()->sortDesc()->mapWithKeys(fn (int $year) => [$year => (string) $year])->all();
    }

    public function content(Schema $schema): Schema
    {
        $estimator = new TaxEstimator(app(FinanceSettings::class), $this->year ?? today()->year);
        $territorial = TerritorialTurnover::forYear($estimator->year);
        $vatThreshold = $estimator->vatExempt() ? $territorial->threshold($estimator) : null;
        $previousYearNotice = $vatThreshold !== null && $estimator->year >= 2026
            ? TerritorialTurnover::forYear($estimator->year - 1)->nextYearNotice()
            : null;

        $isRunningYear = $estimator->year === today()->year;

        return $schema->components([
            ...($estimator->settings()->isIncomplete() ? [self::incompleteSettingsCallout()] : []),
            ...($estimator->settings()->estimatesTaxes() ? [] : [
                Callout::make($estimator->settings()->activityOpened() ? __('Organised accounting') : __('You have not opened your activity yet'))
                    ->description($estimator->settings()->activityOpened()
                        ? __('With organised accounting, personal income tax and Social Security depend on your taxable profit, which your accountant works out. The figures below follow the simplified regime and do not apply to you.')
                        : __('Taxes start on the day you open your activity. The figures below are only a simulation.'))
                    ->icon(Phosphor::Info)
                    ->status('info'),
            ]),
            Callout::make(__('Estimate — check the amounts with your accountant.'))
                ->description(__('See the basis for each amount and the domestic turnover coverage.'))
                ->icon(Phosphor::WarningCircle)
                ->status('warning'),
            View::make('filament.pages.partials.tax-summary')
                ->viewData(['stats' => $this->summary($estimator, $vatThreshold), 'notice' => null]),
            ...(($vatThreshold['notice'] ?? null) !== null ? [
                Callout::make(__('VAT threshold exceeded'))->description($vatThreshold['notice'])->icon(Phosphor::WarningCircle)->status('danger'),
            ] : []),
            ...(($vatThreshold['advisory'] ?? null) !== null ? [
                Callout::make(__('Indicative VAT warning'))->description($vatThreshold['advisory'])->icon(Phosphor::WarningCircle)->status('warning'),
            ] : []),
            ...($previousYearNotice === null ? [] : [
                Callout::make(__('VAT threshold exceeded in the previous year'))->description($previousYearNotice)->icon(Phosphor::WarningCircle)->status('warning'),
            ]),
            // As in the mock: the quarters, with what is due next beside them. What is due only makes sense for the running year.
            // Side by side where the seven columns of the table fit (wide screens); stacked otherwise.
            Grid::make(['default' => 1, '2xl' => $isRunningYear ? 7 : 1])->schema([
                Group::make($this->getWidgetsSchemaComponents([TaxQuartersTable::class], ['year' => $this->year]))
                    ->columnSpan(['default' => 1, '2xl' => $isRunningYear ? 5 : 1]),
                ...($isRunningYear ? [
                    Group::make($this->getWidgetsSchemaComponents([UpcomingTaxPayments::class]))->columnSpan(['default' => 1, '2xl' => 2]),
                ] : []),
            ]),
            $this->turnoverSection(DocumentedTurnover::forYear($estimator->year), $territorial),
            Section::make(__('How the estimate is worked out'))
                ->description(__('The steps behind each figure above.'))
                ->collapsible()
                ->collapsed()
                ->columns(['md' => 2, 'xl' => 4])
                ->schema([
                    Group::make([
                        TextEntry::make('income_heading')->hiddenLabel()->state(__('Earnings'))->weight('semibold'),
                        TextEntry::make('income_note')->hiddenLabel()
                            ->state(__('Received in :year (used as a proxy for relevant income).', ['year' => $estimator->year]))
                            ->color('gray'),
                        TextEntry::make('exempt_note')
                            ->hiddenLabel()
                            ->state($estimator->exemptIncome() > 0
                                ? __('Excluded :amount in tax-exempt payments received.', ['amount' => Money::format($estimator->exemptIncome(), locale: app()->getLocale())])
                                : __('There are no tax-exempt payments received. Mark them in Payments received or on the retainer.'))
                            ->color('gray'),
                    ]),
                    Group::make([
                        TextEntry::make('ss_heading')->hiddenLabel()->state(__('Social Security'))->weight('semibold'),
                        TextEntry::make('ss_note')->hiddenLabel()->color('gray')->state(fn (): string => $this->socialSecurityNote($estimator)),
                    ]),
                    Group::make([
                        TextEntry::make('irs_heading')->hiddenLabel()->state(__('Personal income tax'))->weight('semibold'),
                        ...array_filter($this->irsEntries($estimator), fn (TextEntry $entry): bool => $entry->getName() !== 'irs'),
                    ]),
                    Group::make([
                        TextEntry::make('vat_heading')->hiddenLabel()->state(__('VAT'))->weight('semibold'),
                        ...array_filter($this->vatEntries($estimator, $vatThreshold, $territorial->subtotal() !== null), fn (TextEntry $entry): bool => ! in_array($entry->getName(), ['vat', 'vat_balance'], true)),
                    ]),
                ]),
        ]);
    }

    /**
     * The four figures of the year, as in the mock: what came in, Social Security, the income
     * taxed in IRS and where VAT stands. The steps behind each one are in the section below.
     *
     * @param  array{label: string, amount: int, percent: float, status: string, detail: string, notice: ?string, advisory: ?string, usesReceipts: bool}|null  $threshold
     * @return list<array{label: string, value: string, note?: ?string, valueTone?: ?string, percent?: ?float}>
     */
    private function summary(TaxEstimator $estimator, ?array $threshold): array
    {
        $locale = app()->getLocale();
        $income = $estimator->income();
        $taxable = $estimator->irsTaxableIncome();

        $vat = $threshold !== null
            ? [
                'label' => __('VAT · exempt (art. 53)'),
                'value' => self::thresholdPercent($threshold['percent']),
                'percent' => $threshold['percent'],
                'note' => $threshold['detail'],
                'valueTone' => $threshold['status'] === 'ok' ? null : $threshold['status'],
            ]
            : [
                'label' => __('VAT'),
                'value' => Money::format((int) collect($estimator->vatPeriods())->sum('balance'), locale: $locale),
                'note' => __('Estimated balance for the year'),
            ];

        return [
            [
                'label' => __('Income received'),
                'value' => Money::format($income, locale: $locale),
                'note' => $estimator->exemptIncome() > 0
                    ? __('Excluded :amount in tax-exempt payments received.', ['amount' => Money::format($estimator->exemptIncome(), locale: $locale)])
                    : __('No exempt payments received'),
            ],
            [
                'label' => __('Social Security for the year'),
                'value' => Money::format($estimator->ssYearTotal(), locale: $locale),
                'note' => $this->nextContributionNote($estimator) ?? $this->socialSecurityNote($estimator),
            ],
            [
                'label' => __('Income taxed in IRS'),
                'value' => Money::format($taxable, locale: $locale),
                'note' => match (true) {
                    $estimator->irsJovemExemptToConfirm() => __('Without IRS Jovem, which is to confirm · coefficient :coefficient', [
                        'coefficient' => self::decimal($estimator->effectiveIrsCoefficient()),
                    ]),
                    $income > 0 => __(':percent of what you received · coefficient :coefficient', [
                        'percent' => self::percent(round($taxable / $income, 2)),
                        'coefficient' => self::decimal($estimator->effectiveIrsCoefficient()),
                    ]),
                    default => __('Coefficient :coefficient', ['coefficient' => self::decimal($estimator->effectiveIrsCoefficient())]),
                },
            ],
            $vat,
        ];
    }

    /** "Next: €45.31 by 20 Nov.", while the running year has a contribution still to pay. */
    private function nextContributionNote(TaxEstimator $estimator): ?string
    {
        if ($estimator->year !== today()->year) {
            return null;
        }

        $next = collect((new TaxObligations(app(FinanceSettings::class), CarbonImmutable::today()))->thisMonth())
            ->first(fn (TaxObligation $obligation): bool => $obligation->kind === 'ss' && $obligation->status === 'due' && (int) $obligation->amount > 0);

        return $next === null ? null : __('Next: :amount by :date', [
            'amount' => Money::format((int) $next->amount, locale: app()->getLocale()),
            'date' => $next->dueOn->locale(app()->getLocale())->translatedFormat(__('j M')),
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
     * Taxable income, then each step: gross, IRS Jovem, coefficient used and why, the contributions
     * deducted (DES001) and the expenses missing to reach 15 % (DES002).
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
            $estimator->settings()->irsJovem() ? $line('irs_jovem', match (true) {
                $estimator->irsJovemExemptToConfirm() => __('IRS Jovem (:percent, year :year): up to :amount may be exempt, to confirm. It is not taken off the taxable income above.', [
                    'percent' => self::percent($jovemPercent),
                    'year' => $estimator->settings()->irsJovemYearNumber($estimator->year),
                    'amount' => Money::format($jovemExempt, locale: app()->getLocale()),
                ]),
                $jovemPercent > 0 => __('Exempt under IRS Jovem (:percent, year :year): −:amount', [
                    'percent' => self::percent($jovemPercent),
                    'year' => $estimator->settings()->irsJovemYearNumber($estimator->year),
                    'amount' => Money::format($jovemExempt, locale: app()->getLocale()),
                ])
                    .($jovemExempt === TaxEstimator::IRS_JOVEM_IAS_CAP * $estimator->ias()
                        ? __(' (cap of :cap × IAS:provisional)', [
                            'cap' => TaxEstimator::IRS_JOVEM_IAS_CAP,
                            'provisional' => $estimator->iasIsProvisional() ? __(', provisional') : '',
                        ])
                        : ''),
                default => __('IRS Jovem does not apply in :year (age or year of eligibility).', ['year' => $estimator->year]),
            }) : null,
            $estimator->irsJovemExemptToConfirm()
                ? $line('irs_jovem_cap', __('The IRS Jovem cap of :cap × IAS is one per person and also counts your salary of :year, so it is never doubled. How it is split between the salary and your receipts has not been confirmed: check it with your accountant.', [
                    'cap' => TaxEstimator::IRS_JOVEM_IAS_CAP,
                    'year' => $estimator->year,
                ]))
                : null,
            $line('irs_coefficient', __('Coefficient :coefficient', ['coefficient' => self::decimal($estimator->effectiveIrsCoefficient())])
                .match (true) {
                    $reduction > 0 => __(' (:base reduced by :percent — :year)', [
                        'base' => self::decimal($estimator->settings()->irsCoefficient()),
                        'percent' => self::percent($reduction),
                        'year' => $reduction === 0.5 ? __('1st year') : __('2nd year'),
                    ]),
                    $estimator->irsCoefficientReductionBlocker() === 'dependent_income' => __(' (no reduction: year with a salary or pension)'),
                    $estimator->irsCoefficientReductionBlocker() === 'recent_restart' => __(' (no reduction: restarted less than five years after ceasing activity)'),
                    $estimator->settings()->sellsGoodsUnderIrs() => __(' (sales of goods: no reduction in the first years)'),
                    default => '',
                }),
            ($deduction = $estimator->irsContributionsDeduction()) > 0
                ? $line('irs_contributions', __('Social Security contributions above 10% of gross income: −:amount (Article 31(2))', [
                    'amount' => Money::format($deduction, locale: app()->getLocale()),
                ]))
                : null,
            match ($shortfall = $estimator->irsExpenseShortfall()) {
                null => $line('irs_expenses', __('The 15% expenses check is not calculated with IRS Jovem: how the two combine has not been confirmed. Check it with your accountant.')),
                0 => $estimator->settings()->sellsGoodsUnderIrs() ? null : $line('irs_expenses', __('Expenses cover the 15% of gross income required (Article 31(13)).')),
                default => $line('irs_expenses', __('Expenses missing to reach 15% of gross income: +:amount (Article 31(13)). Business expenses recorded: :expenses.', [
                    'amount' => Money::format($shortfall, locale: app()->getLocale()),
                    'expenses' => Money::format($estimator->irsEligibleExpenses(), locale: app()->getLocale()),
                ])),
            },
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
            // With a job, the months whose quarter reached 4 IAS a month pay on the excess (SS042).
            $estimator->settings()->ssContributionExempt() && $estimator->settings()->ssExemptionReason() === 'accumulation' && $estimator->ssYearTotal() > 0 => __('Exempt (Article 157), except on the excess over 4 IAS a month in the quarters that reached it.'),
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
