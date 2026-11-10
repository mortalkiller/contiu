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
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use ToneGabes\Filament\Icons\Enums\Phosphor;
use UnitEnum;

class WithholdingReviewPage extends Page
{
    use HasTaxSubNavigation;

    protected static ?string $title = 'Retenções na fonte';

    protected static ?string $slug = 'retencoes-na-fonte';

    protected static string|UnitEnum|null $navigationGroup = 'Impostos';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::Receipt;

    protected static ?int $navigationSort = 7;

    protected string $view = 'filament.pages.withholding-review';

    #[Url]
    public ?int $year = null;

    #[Locked]
    #[Url(as: 'allocation')]
    public ?int $allocationId = null;

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
            'retained' => ['required', 'regex:/\A[0-9]{1,9}(?:[,.][0-9]{1,2})?\z/'],
            ...($this->allocationId === null ? [] : ['accepted' => ['accepted']]),
        ], [
            'documentId.*' => 'Escolhe o documento de origem.',
            'retained.*' => 'Introduz o montante retido em euros com até duas casas decimais.',
            'accepted.accepted' => 'Confirma que o recibo corresponde a esta afetação.',
        ]);

        WithholdingReview::record((int) $data['documentId'], Money::toCents($data['retained']), $this->year, $this->allocationId);
        $this->reset('documentId', 'retained', 'accepted');

        Notification::make()->title('Retenção documentada registada')->success()->send();
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess() && $this->accountId === CurrentAccount::id(), 403);

        $allocation = $this->allocation();
        $sources = match (true) {
            ! static::canRecord() => collect(),
            $allocation !== null => WithholdingReview::sourcesForAllocation($allocation),
            default => WithholdingReview::sourcesForYear($this->year),
        };

        $review = WithholdingReview::forYear($this->year);
        $stale = $review->rows->where('current', false)->count();

        return [
            'summary' => [
                [
                    'label' => 'Total retido com fonte conferida',
                    'value' => $review->retainedTotal === null ? '—' : Money::format($review->retainedTotal),
                    'note' => trans_choice('{0} Sem retenções em :year|{1} 1 retenção em :year|[2,*] :count retenções em :year', $review->rows->count(), ['year' => $this->year]),
                ],
                [
                    'label' => 'Por conferir',
                    'value' => (string) $stale,
                    'note' => $stale === 0 ? 'Todas as fontes conferem' : 'A fonte mudou depois do registo',
                    'tone' => $stale === 0 ? 'success' : 'warning',
                ],
                ...($allocation !== null || ! static::canRecord() ? [] : [[
                    'label' => 'Recibos sem retenção registada',
                    'value' => (string) $sources->count(),
                    'note' => 'Só precisas de registar os que tiveram retenção',
                ]]),
            ],
            'review' => $review,
            'allocation' => $allocation,
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
}
