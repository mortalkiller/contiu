<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\HasTaxSubNavigation;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Support\AnnualFiscalReview as Report;
use App\Support\DocumentedTurnover;
use App\Support\Money;
use App\Support\PeriodSummary;
use App\Support\WithholdingReview;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
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

    public function content(Schema $schema): Schema
    {
        $years = collect(array_keys(PeriodSummary::availableYears()))->merge(DocumentedTurnover::issueYears())
            ->unique()->sortDesc()->mapWithKeys(fn (int $year): array => [$year => (string) $year])->all();
        $year = isset($years[$this->year]) ? $this->year : today()->year;
        $report = new Report($year);
        $totals = $report->totals();
        $withholding = WithholdingReview::forYear($year);
        $contributions = $report->confirmedContributions();

        $stale = $withholding->rows->where('current', false)->count();
        $toReview = $totals['otherCurrencies'] + $totals['expensesWithoutProof'] + $totals['expensesToReview'] + $stale;

        return $schema->components([
            Callout::make($toReview === 0 ? __('Ready for the accountant: nothing to review') : trans_choice('{1} Review 1 item before handing over to your accountant|[2,*] Review :count items before handing over to your accountant', $toReview))
                ->description($toReview === 0
                    ? __('Documents, expenses and withholding for :year have nothing pending.', ['year' => $year])
                    : __('See “Items to review” for details: other currencies, expenses with missing proof or awaiting review, and withholding with changed source documents.'))
                ->icon($toReview === 0 ? Phosphor::CheckCircle : Phosphor::WarningCircle)
                ->status($toReview === 0 ? 'success' : 'warning'),
            ...($stale === 0 ? [] : [
                Callout::make(trans_choice('{1} 1 withholding record has a changed source and is excluded from the total|[2,*] :count withholding records have changed sources and are excluded from the total', $stale))
                    ->icon(Phosphor::WarningCircle)
                    ->status('warning'),
            ]),
            Section::make()->schema([
                Select::make('year')->label(__('Year'))->options($years)->selectablePlaceholder(false)->live(),
            ]),
            Section::make(__('Transactions for the year'))
                ->columns(['md' => 2, 'xl' => 4])
                ->schema([
                    $this->source('billed', __('Documented net invoicing in EUR'), $totals['billed'], __('View documents'),
                        FiscalDocumentResource::canViewAny() && $totals['billed'] !== null
                            ? FiscalDocumentResource::getUrl('index', $this->filter('billed_year', $year)) : null),
                    $this->source('received', __('Receipts by payment date'), $totals['received'], __('View payments received'),
                        PaymentResource::canViewAny() ? PaymentResource::getUrl('index', $this->filter('year', $year)) : null),
                    $this->source('expenses', __('Recorded expenses by total amount'), $totals['expenses'], __('View expenses'),
                        ExpenseResource::canViewAny() ? ExpenseResource::getUrl('index', $this->filter('year', $year)) : null),
                    $this->source('taxPaid', __('Taxes and contributions paid'), $totals['taxPaid'], __('View tax and contribution payments'),
                        ExpenseResource::canViewAny() ? ExpenseResource::getUrl('index', $this->filter('paid_tax_year', $year)) : null),
                ]),
            Section::make(__('Items to review'))
                ->columns(3)
                ->schema([
                    TextEntry::make('otherCurrencies')->label(__('Billing documents in other currencies'))->state($totals['otherCurrencies']),
                    TextEntry::make('expensesWithoutProof')->label(__('Expenses without proof'))->state($totals['expensesWithoutProof']),
                    TextEntry::make('expensesToReview')->label(__('Expenses to review'))->state($totals['expensesToReview']),
                    TextEntry::make('withholdingToReview')->label(__('Withholding to review after source document changes'))->state($withholding->rows->where('current', false)->count()),
                ]),
            Section::make(__('Tax confirmations'))
                ->columns(['md' => 2])
                ->schema([
                    $this->source('withholding', __('Documented withholding'), $withholding->retainedTotal, __('View documents and payments received'),
                        WithholdingReviewPage::canAccess() ? WithholdingReviewPage::getUrl(['year' => $year]) : null, __('No confirmed withholding')),
                    Section::make(__('Confirmed Social Security contributions'))
                        ->schema([
                            TextEntry::make('confirmedSocialSecurity')->hiddenLabel()
                                ->state($contributions->isEmpty() ? __('No confirmed contributions') : Money::format((int) $contributions->sum('amount'), locale: app()->getLocale()))
                                ->weight('bold'),
                            RepeatableEntry::make('contributions')->hiddenLabel()->contained(false)
                                ->state($contributions->map(fn ($row): array => [
                                    'month' => $row->contribution_month->format('m/Y'),
                                    'quarter' => Report::incomeQuarter($row->contribution_month),
                                    'amount' => $row->amount,
                                ])->all())
                                ->columns(3)
                                ->schema([
                                    TextEntry::make('month')->label(__('Month')),
                                    TextEntry::make('amount')->label(__('Confirmed'))->money('EUR', divideBy: 100),
                                    TextEntry::make('quarter')->label(__('Source breakdown'))
                                        ->url(fn (string $state): string => SocialSecurity::getUrl(['quarter' => $state])),
                                ]),
                        ]),
                ]),
        ]);
    }

    private function source(string $name, string $label, ?int $amount, string $linkLabel, ?string $url, ?string $empty = null): Section
    {
        return Section::make($label)->schema([
            TextEntry::make($name)->hiddenLabel()->state($amount === null ? ($empty ?? __('No documents in EUR')) : Money::format($amount, locale: app()->getLocale()))->weight('bold'),
            ...($url === null ? [] : [
                TextEntry::make($name.__('Link'))->hiddenLabel()->state($linkLabel)->url($url)->color('primary'),
            ]),
        ]);
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
