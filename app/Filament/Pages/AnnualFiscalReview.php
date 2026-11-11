<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\HasTaxSubNavigation;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Support\PeriodPicker;
use App\Support\AnnualFiscalReview as Report;
use App\Support\DocumentedTurnover;
use App\Support\Money;
use App\Support\PeriodSummary;
use App\Support\WithholdingReview;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Support\Enums\TextSize;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class AnnualFiscalReview extends Page
{
    use HasTaxSubNavigation;

    protected static ?string $slug = 'resumo-anual-fiscal';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::ClipboardText;

    #[Url]
    public ?int $year = null;

    public function mount(): void
    {
        $this->year ??= today()->year;
    }

    public function getSubheading(): string
    {
        return __('The year in one place, to hand over to your accountant.');
    }

    /** As in the mock: the year to show. */
    protected function getHeaderActions(): array
    {
        return [
            PeriodPicker::make('year', $this->yearOptions(), $this->shownYear(), fn (int|string $year) => $this->year = (int) $year, __('Year')),
        ];
    }

    /** @return array<int, string> */
    private function yearOptions(): array
    {
        return collect(array_keys(PeriodSummary::availableYears()))->merge(DocumentedTurnover::issueYears())->push(today()->year)
            ->unique()->sortDesc()->mapWithKeys(fn (int $year): array => [$year => (string) $year])->all();
    }

    private function shownYear(): int
    {
        return isset($this->yearOptions()[$this->year]) ? (int) $this->year : today()->year;
    }

    public function content(Schema $schema): Schema
    {
        $year = $this->shownYear();
        $report = new Report($year);
        $totals = $report->totals();
        $withholding = WithholdingReview::forYear($year);
        $contributions = $report->confirmedContributions();
        $locale = app()->getLocale();

        $stale = $withholding->rows->where('current', false)->count();
        $toReview = $totals['otherCurrencies'] + $totals['expensesWithoutProof'] + $totals['expensesToReview'] + $stale;
        $expensesUrl = fn (array $filters): ?string => ExpenseResource::canViewAny() ? ExpenseResource::getUrl('index', ['tableFilters' => $filters]) : null;

        return $schema->components([
            // As in the mock: whether the year is ready, one step per kind of thing to sort out.
            View::make('filament.pages.partials.steps')->viewData([
                'heading' => $toReview === 0
                    ? __('Ready for the accountant: nothing to review')
                    : trans_choice('{1} Review 1 item before handing over to your accountant|[2,*] Review :count items before handing over to your accountant', $toReview),
                'steps' => [
                    [
                        'label' => __('Billing documents in other currencies'),
                        'state' => $totals['otherCurrencies'] === 0 ? __('Nothing to review') : trans_choice('{1} 1 to review|[2,*] :count to review', $totals['otherCurrencies']),
                        'done' => $totals['otherCurrencies'] === 0,
                        'url' => FiscalDocumentResource::canViewAny() ? FiscalDocumentResource::getUrl('index', $this->filter('billed_year', $year)) : null,
                    ],
                    [
                        'label' => __('Expenses without proof'),
                        'state' => $totals['expensesWithoutProof'] === 0 ? __('Nothing to review') : trans_choice('{1} 1 to attach|[2,*] :count to attach', $totals['expensesWithoutProof']),
                        'done' => $totals['expensesWithoutProof'] === 0,
                        'url' => $expensesUrl(['year' => ['value' => $year]]),
                    ],
                    [
                        'label' => __('Expenses to review'),
                        'state' => $totals['expensesToReview'] === 0 ? __('Nothing to review') : trans_choice('{1} 1 to review|[2,*] :count to review', $totals['expensesToReview']),
                        'done' => $totals['expensesToReview'] === 0,
                        'url' => $expensesUrl(['year' => ['value' => $year]]),
                    ],
                    [
                        'label' => __('Withholding'),
                        'state' => $stale === 0 ? __('All verified') : trans_choice('{1} 1 to verify|[2,*] :count to verify', $stale),
                        'done' => $stale === 0,
                        'url' => WithholdingReviewPage::canAccess() ? WithholdingReviewPage::getUrl(['year' => $year]) : null,
                    ],
                ],
            ]),
            View::make('filament.pages.partials.tax-summary')->viewData(['notice' => null, 'stats' => [
                $this->stat(__('Invoiced'), $totals['billed'], __('View documents'),
                    FiscalDocumentResource::canViewAny() && $totals['billed'] !== null ? FiscalDocumentResource::getUrl('index', $this->filter('billed_year', $year)) : null),
                $this->stat(__('Payments received'), $totals['received'], __('View payments received'),
                    PaymentResource::canViewAny() ? PaymentResource::getUrl('index', $this->filter('year', $year)) : null),
                $this->stat(__('Expenses'), $totals['expenses'], __('View expenses'), $expensesUrl(['year' => ['value' => $year]])),
                $this->stat(__('Taxes paid'), $totals['taxPaid'], __('View tax and contribution payments'), $expensesUrl(['paid_tax_year' => ['value' => $year]])),
            ]]),
            Grid::make(['default' => 1, 'lg' => 2])->schema([
                Section::make(__('Confirmed Social Security contributions'))
                    ->schema([
                        View::make('filament.pages.partials.annual-contributions')->viewData([
                            'rows' => $contributions->map(fn ($row): array => [
                                'month' => Str::ucfirst($row->contribution_month->locale($locale)->translatedFormat('F')),
                                'amount' => Money::format((int) $row->amount, locale: $locale),
                                'quarter' => Report::incomeQuarter($row->contribution_month),
                                'quarterLabel' => __('Declaration for :quarter', ['quarter' => mb_strtolower(SocialSecurity::quarterRange(Report::incomeQuarter($row->contribution_month)))]),
                            ])->all(),
                            'total' => Money::format((int) $contributions->sum('amount'), locale: $locale),
                        ]),
                    ]),
                Section::make(__('Documented withholding'))
                    ->headerActions([
                        Action::make('withholding')->label(__('Open withholding'))->link()
                            ->visible(WithholdingReviewPage::canAccess())
                            ->url(fn (): string => WithholdingReviewPage::getUrl(['year' => $year])),
                    ])
                    ->schema([
                        TextEntry::make('withholding')->label(__('Total withheld with verified source'))
                            ->state($withholding->retainedTotal === null ? __('No confirmed withholding') : Money::format($withholding->retainedTotal, locale: $locale))
                            ->size(TextSize::Large)->weight('bold')
                            ->helperText(trans_choice('{0} No withholding in :year|{1} 1 withholding record in :year|[2,*] :count withholding records in :year', $withholding->rows->count(), ['year' => $year])),
                        ...($stale === 0 ? [] : [
                            Callout::make(trans_choice('{1} 1 withholding record has a changed source and is excluded from the total|[2,*] :count withholding records have changed sources and are excluded from the total', $stale))
                                ->icon(Phosphor::WarningCircle)
                                ->status('warning'),
                        ]),
                    ]),
            ]),
        ]);
    }

    /** @return array{label: string, value: string, note: ?string, url: ?string} */
    private function stat(string $label, ?int $amount, string $linkLabel, ?string $url): array
    {
        return [
            'label' => $label,
            // A missing figure is a dash, as on every card; why it is missing goes underneath.
            'value' => $amount === null ? '—' : Money::format($amount, locale: app()->getLocale()),
            'note' => $amount === null ? __('No documents in EUR') : ($url === null ? null : $linkLabel),
            'url' => $url,
        ];
    }

    /** @return array{tableFilters: array<string, array{value: int}>} */
    private function filter(string $filter, int $year): array
    {
        return ['tableFilters' => [$filter => ['value' => $year]]];
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return __('Annual tax summary');
    }

    #[Unexposed]
    public static function getNavigationGroup(): string
    {
        return __('Taxes');
    }

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('Annual tax summary');
    }
}
