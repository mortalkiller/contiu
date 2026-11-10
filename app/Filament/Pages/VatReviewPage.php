<?php

namespace App\Filament\Pages;

use App\Enums\FiscalDocumentDirection;
use App\Filament\Pages\Concerns\HasTaxSubNavigation;
use App\Models\FiscalDocument;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;
use App\Support\Money;
use App\Support\TaxEstimator;
use App\Support\TerritorialTurnover;
use App\Support\VatPeriodReview;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use ToneGabes\Filament\Icons\Enums\Phosphor;
use UnitEnum;

class VatReviewPage extends Page
{
    use HasTaxSubNavigation;

    protected static ?string $title = 'Revisão de IVA';

    protected static ?string $slug = 'revisao-iva';

    protected static string|UnitEnum|null $navigationGroup = 'Impostos';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::Percent;

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.vat-review';

    #[Url]
    public ?int $year = null;

    #[Url]
    public ?int $month = null;

    #[Url]
    public int $months = 1;

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
        $this->liquidated = $confirmation === null ? '' : (string) Money::toEuros($confirmation->liquidated);
        $this->deductible = $confirmation === null ? '' : (string) Money::toEuros($confirmation->deductible);
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
            throw ValidationException::withMessages(['reference' => 'O período mudou. Atualiza os dados antes de confirmar.']);
        }

        $data = $this->validate([
            'liquidated' => ['required', 'regex:/\A[0-9]{1,9}(?:[,.][0-9]{1,2})?\z/'],
            'deductible' => ['required', 'regex:/\A[0-9]{1,9}(?:[,.][0-9]{1,2})?\z/'],
            'adjustments' => ['required', 'regex:/\A-?[0-9]{1,9}(?:[,.][0-9]{1,2})?\z/'],
            'reference' => ['required', 'string', 'max:500'],
        ], [
            '*.required' => 'Preenche este campo.',
            '*.regex' => 'Introduz um montante em euros com até duas casas decimais.',
            'reference.max' => 'A referência pode ter até 500 caracteres.',
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
        Notification::make()->title('Apuramento de IVA confirmado')->success()->send();
    }

    protected function getViewData(): array
    {
        $review = $this->review();

        return ['review' => $review, ...$this->summary($review)];
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
        $documents = fn (int $number): string => trans_choice('{0} Sem documentos no período|{1} 1 documento no período|[2,*] :count documentos no período', $number);

        return [
            'summary' => [
                ['label' => 'IVA nos documentos emitidos', 'value' => Money::format($review->issuedVat), 'note' => $documents($count(FiscalDocumentDirection::Issued))],
                ['label' => 'IVA nos documentos recebidos', 'value' => Money::format($review->receivedVat), 'note' => $documents($count(FiscalDocumentDirection::Received))],
                ...($threshold === null ? [] : [[
                    'label' => 'Limite de isenção',
                    'value' => number_format($threshold['percent'], 1, ',', ' ').'%',
                    'percent' => $threshold['percent'],
                    'note' => $threshold['detail'],
                    'tone' => $threshold['status'] === 'ok' ? null : $threshold['status'],
                ]]),
            ],
            'summaryNotice' => $threshold === null ? null : [
                'title' => 'Tens isenção de IVA (art. 53.º)',
                'body' => 'Não entregas declaração periódica. Esta página serve para vigiares o limite e conferires o IVA dos documentos recebidos.',
            ],
        ];
    }

    private function review(): VatPeriodReview
    {
        abort_unless(static::canAccess(), 403);

        return VatPeriodReview::forPeriod($this->year, $this->month, $this->months);
    }
}
