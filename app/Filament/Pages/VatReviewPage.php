<?php

namespace App\Filament\Pages;

use App\Enums\FiscalDocumentDirection;
use App\Filament\Pages\Concerns\HasTaxSubNavigation;
use App\Filament\Support\PeriodPicker;
use App\Models\FiscalDocument;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;
use App\Support\Money;
use App\Support\Period;
use App\Support\TaxEstimator;
use App\Support\TerritorialTurnover;
use App\Support\VatPeriodReview;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class VatReviewPage extends Page
{
    use HasTaxSubNavigation;

    protected static ?string $slug = 'revisao-iva';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::Percent;

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.vat-review';

    #[Url]
    public ?int $year = null;

    #[Url]
    public ?int $month = null;

    #[Url]
    public int $months = 1;

    /** Which documents the table lists: issued or received. */
    public string $direction = 'issued';

    #[Locked]
    public string $revision = '';

    public string $liquidated = '';

    public string $deductible = '';

    public string $adjustments = '0';

    public string $reference = '';

    public string $dueOn = '';

    public string $paidOn = '';

    public static function canAccess(): bool
    {
        return CurrentAccount::get() !== null && Gate::allows('viewAny', FiscalDocument::class);
    }

    public static function canConfirm(): bool
    {
        return static::canAccess() && ! CurrentAccount::readOnly() && Gate::allows('update', new FiscalDocument);
    }

    public function mount(?int $year = null, ?int $month = null, ?int $months = null): void
    {
        $this->year = $year ?? $this->year ?? today()->year;
        $this->month = $month ?? $this->month ?? today()->month;
        $this->months = $months ?? $this->months;
        $this->reloadReview();
    }

    public function getSubheading(): string
    {
        return __('VAT for the period, from the fiscal documents.');
    }

    /** As in the mock: the period to show, then whether periods are months or quarters. */
    protected function getHeaderActions(): array
    {
        $periods = [];

        foreach (array_unique([today()->year, $this->year, $this->year - 1]) as $year) {
            foreach (array_reverse($this->months === 3 ? [1, 4, 7, 10] : range(1, 12)) as $month) {
                if (CarbonImmutable::create($year, $month, 1)->lte(today())) {
                    $periods[sprintf('%04d-%02d', $year, $month)] = static::periodLabel($year, $month, $this->months);
                }
            }
        }

        krsort($periods);
        $current = sprintf('%04d-%02d', $this->year, $this->month);
        $periods[$current] ??= static::periodLabel($this->year, $this->month, $this->months);

        return [
            PeriodPicker::make('period', $periods, $current, function (int|string $period): void {
                [$this->year, $this->month] = array_map('intval', explode('-', (string) $period));
                $this->reloadReview();
            }, __('Period')),
            ActionGroup::make([
                Action::make('monthly')
                    ->label(__('Monthly'))
                    ->color($this->months === 1 ? 'primary' : 'gray')
                    ->action(function (): void {
                        $this->months = 1;
                        $this->reloadReview();
                    }),
                Action::make('quarterly')
                    ->label(__('Quarterly'))
                    ->color($this->months === 3 ? 'primary' : 'gray')
                    ->action(function (): void {
                        $this->months = 3;
                        $this->reloadReview();
                    }),
            ])->buttonGroup(),
        ];
    }

    /** "4.º trimestre de 2026" or "novembro de 2026". */
    public static function periodLabel(int $year, int $month, int $months): string
    {
        $monthName = app()->getLocale() === 'pt_PT'
            ? Period::MONTHS[$month - 1]
            : CarbonImmutable::create(2000, $month, 1)->locale(app()->getLocale())->translatedFormat('F');

        return $months === 3
            ? __('Q:quarter :year', ['quarter' => intdiv($month - 1, 3) + 1, 'year' => $year])
            : __(':month, :year', ['month' => $monthName, 'year' => $year]);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['year', 'month', 'months'], true)) {
            $this->reloadReview();
        }
    }

    public function reloadReview(): void
    {
        $this->year = $this->year >= 1900 && $this->year <= 9998 ? $this->year : today()->year;
        $this->month = $this->month >= 1 && $this->month <= 12 ? $this->month : today()->month;
        $this->months = $this->months === 3 ? 3 : 1;

        if ($this->months === 3) {
            $this->month = intdiv($this->month - 1, 3) * 3 + 1;
        }

        $review = $this->review();
        $confirmation = $review->confirmation;
        $this->revision = $review->revision();
        // Before a confirmation, the documents' VAT is the starting point; while exempt under
        // art. 53.º nothing on the received documents is deductible.
        $exempt = (new TaxEstimator(app(FinanceSettings::class), $this->year))->vatExempt();
        $this->liquidated = (string) Money::toEuros($confirmation?->liquidated ?? $review->issuedVat);
        $this->deductible = (string) Money::toEuros($confirmation?->deductible ?? ($exempt ? 0 : $review->receivedVat));
        $this->adjustments = (string) Money::toEuros($confirmation?->adjustments ?? 0);
        $this->reference = $confirmation?->reference ?? '';
        $this->dueOn = $confirmation?->due_on?->toDateString() ?? '';
        $this->paidOn = $confirmation?->paid_on?->toDateString() ?? '';
        $this->resetValidation();
    }

    public function confirm(): void
    {
        abort_unless(static::canConfirm(), 403);
        $review = $this->review();

        if ($review->revision() !== $this->revision) {
            throw ValidationException::withMessages(['reference' => __('The period changed. Refresh the details before confirming.')]);
        }

        $data = $this->validate([
            'liquidated' => ['required', 'regex:/\A[0-9]{1,9}(?:[,.][0-9]{1,2})?\z/'],
            'deductible' => ['required', 'regex:/\A[0-9]{1,9}(?:[,.][0-9]{1,2})?\z/'],
            'adjustments' => ['required', 'regex:/\A-?[0-9]{1,9}(?:[,.][0-9]{1,2})?\z/'],
            'reference' => ['required', 'string', 'max:500'],
        ], [
            '*.required' => __('Fill in this field.'),
            '*.regex' => __('Enter a euro amount with up to two decimal places.'),
            'reference.max' => __('The reference can have up to 500 characters.'),
        ]);

        $review->confirm(
            Money::toCents($data['liquidated']),
            Money::toCents($data['deductible']),
            Money::toCents($data['adjustments']),
            $data['reference'],
            $this->dueOn === '' ? null : $this->dueOn,
            $this->paidOn === '' ? null : $this->paidOn,
        );

        $this->reloadReview();
        Notification::make()->title(__('VAT assessment confirmed'))->success()->send();
    }

    protected function getViewData(): array
    {
        $review = $this->review();

        return [
            'review' => $review,
            'periodLabel' => static::periodLabel($this->year, $this->month, $this->months),
            ...$this->summary($review),
        ];
    }

    /**
     * The period at a glance: VAT on issued and received documents and, while exempt under
     * art. 53.º, how much of the exemption limit the year has used.
     *
     * @return array{summary: list<array<string, mixed>>, summaryNotice: ?array{title: string, body: string}}
     */
    private function summary(VatPeriodReview $review): array
    {
        $estimator = new TaxEstimator(app(FinanceSettings::class), $this->year);
        $threshold = $estimator->vatExempt() ? TerritorialTurnover::forYear($this->year)->threshold($estimator) : null;
        $count = fn (FiscalDocumentDirection $direction): int => $review->documents
            ->filter(fn (array $row): bool => $row['document']->direction === $direction)->count();
        $documents = fn (int $number): string => trans_choice('{0} No documents in this period|{1} 1 document in this period|[2,*] :count documents in this period', $number);

        return [
            'summary' => [
                ['label' => __('VAT on issued documents'), 'value' => Money::format($review->issuedVat, locale: app()->getLocale()), 'note' => $documents($count(FiscalDocumentDirection::Issued))],
                ['label' => __('VAT on received documents'), 'value' => Money::format($review->receivedVat, locale: app()->getLocale()), 'note' => $documents($count(FiscalDocumentDirection::Received))],
                ...($threshold === null ? [] : [[
                    'label' => __('Exemption threshold'),
                    'value' => Number::format($threshold['percent'], precision: 1, locale: app()->getLocale()).'%',
                    'percent' => $threshold['percent'],
                    'note' => $threshold['detail'],
                    'valueTone' => $threshold['status'] === 'ok' ? null : $threshold['status'],
                ]]),
            ],
            'summaryNotice' => $threshold === null ? null : [
                'title' => __('You are VAT exempt (Article 53)'),
                'body' => __('You do not file a periodic VAT return. Use this page to track the threshold and check VAT on received documents.'),
            ],
        ];
    }

    private function review(): VatPeriodReview
    {
        abort_unless(static::canAccess(), 403);

        return VatPeriodReview::forPeriod($this->year, $this->month, $this->months);
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return __('VAT review');
    }

    #[Unexposed]
    public static function getNavigationGroup(): string
    {
        return __('Taxes');
    }

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('VAT review');
    }
}
