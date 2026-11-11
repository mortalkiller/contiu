<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\HasTaxSubNavigation;
use App\Models\FiscalDocument;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Support\CurrentAccount;
use App\Support\Money;
use App\Support\WithholdingReview;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class WithholdingReviewPage extends Page
{
    use HasTaxSubNavigation;

    protected static ?string $slug = 'retencoes-na-fonte';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::Receipt;

    protected static ?int $navigationSort = 7;

    protected string $view = 'filament.pages.withholding-review';

    #[Url]
    public ?int $year = null;

    #[Locked]
    #[Url(as: 'allocation')]
    public ?int $allocationId = null;

    #[Locked]
    #[Url]
    public bool $ordinary = false;

    #[Locked]
    public int $accountId;

    public string $documentId = '';

    public string $retained = '';

    public bool $accepted = false;

    public static function canAccess(): bool
    {
        return CurrentAccount::get() !== null
            && Gate::allows('viewAny', FiscalDocument::class)
            && Gate::allows('viewAny', Payment::class);
    }

    public static function canRecord(): bool
    {
        return static::canAccess() && ! CurrentAccount::readOnly() && Gate::allows('update', new FiscalDocument);
    }

    public function mount(?int $year = null): void
    {
        $this->accountId = CurrentAccount::id();
        $this->year = $year ?? $this->year;
        $this->updatedYear();
    }

    public function updatedYear(): void
    {
        $this->year = $this->allocation()?->payment->paid_on->year
            ?? ($this->year >= 1900 && $this->year <= 9998 ? $this->year : today()->year);
        $this->reset('documentId', 'retained', 'accepted');
        $this->resetValidation();
    }

    public function updatedDocumentId(): void
    {
        $this->reset('retained', 'accepted');
        $this->resetValidation();
    }

    public function record(): void
    {
        abort_unless(static::canRecord() && $this->accountId === CurrentAccount::id(), 403);

        $data = $this->validate([
            'documentId' => ['required', 'integer', 'min:1'],
            ...($this->allocationId !== null && $this->ordinary ? [] : ['retained' => ['required', 'regex:/\A[0-9]{1,9}(?:[,.][0-9]{1,2})?\z/']]),
            ...($this->allocationId === null ? [] : ['accepted' => ['accepted']]),
        ], [
            'documentId.*' => __('Choose the source document.'),
            'retained.*' => __('Enter the withheld amount in euros with up to two decimal places.'),
            'accepted.accepted' => __('Confirm that the receipt matches this allocation.'),
        ]);

        $ordinary = $this->allocationId !== null && $this->ordinary;
        if ($ordinary) {
            WithholdingReview::reconcileReceipt((int) $data['documentId'], $this->allocationId);
        } else {
            WithholdingReview::record((int) $data['documentId'], Money::toCents($data['retained']), $this->year, $this->allocationId);
        }
        $this->reset('documentId', 'retained', 'accepted');

        Notification::make()->title($ordinary ? __('Receipt reconciled') : __('Documented withholding recorded'))->success()->send();
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess() && $this->accountId === CurrentAccount::id(), 403);

        $allocation = $this->allocation();
        $ordinary = $allocation !== null && $this->ordinary;
        $preview = null;
        $previewError = null;
        if ($allocation !== null && static::canRecord()) {
            try {
                $preview = WithholdingReview::previewAllocation($allocation);
            } catch (ValidationException $exception) {
                $previewError = $exception->getMessage();
            }
        }

        $sources = match (true) {
            ! static::canRecord() => collect(),
            $allocation !== null => $preview === null ? collect() : WithholdingReview::sourcesForAllocation($allocation, $ordinary),
            default => WithholdingReview::sourcesForYear($this->year),
        };

        $review = WithholdingReview::forYear($this->year);
        $stale = $review->rows->where('current', false)->count();

        return [
            'summary' => [
                [
                    'label' => __('Total withholding with verified source'),
                    'value' => $review->retainedTotal === null ? '—' : Money::format($review->retainedTotal, locale: app()->getLocale()),
                    'note' => trans_choice('{0} No withholding in :year|{1} 1 withholding record in :year|[2,*] :count withholding records in :year', $review->rows->count(), ['year' => $this->year]),
                ],
                [
                    'label' => __('Pending verification'),
                    'value' => (string) $stale,
                    'note' => $stale === 0 ? __('All sources match') : __('The source changed after recording'),
                    'tone' => $stale === 0 ? 'success' : 'warning',
                ],
                ...($allocation !== null || ! static::canRecord() ? [] : [[
                    'label' => __('Receipts with no withholding recorded'),
                    'value' => (string) $sources->count(),
                    'note' => __('Record only the receipts where tax was withheld'),
                ]]),
            ],
            'review' => $review,
            'allocation' => $allocation,
            'ordinary' => $ordinary,
            'preview' => $preview,
            'previewError' => $previewError,
            'sources' => $sources,
            'selected' => $sources->firstWhere('id', $this->documentId),
        ];
    }

    private function allocation(): ?PaymentAllocation
    {
        return $this->allocationId === null ? null : PaymentAllocation::query()
            ->with(['receivable.billable', 'payment.bankLink.bankTransaction'])
            ->findOrFail($this->allocationId);
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return __('Withholding taxes');
    }

    #[Unexposed]
    public static function getNavigationGroup(): string
    {
        return __('Taxes');
    }

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('Withholding taxes');
    }
}
