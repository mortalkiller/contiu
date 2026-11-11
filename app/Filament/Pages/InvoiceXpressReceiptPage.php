<?php

namespace App\Filament\Pages;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Enums\Language;
use App\Exceptions\InvoiceXpressException;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLink;
use App\Models\InvoiceXpressConfig;
use App\Models\InvoiceXpressReceiptAttempt;
use App\Models\InvoiceXpressSnapshot;
use App\Models\PaymentAllocation;
use App\Support\CurrentAccount;
use App\Support\InvoiceXpressIssue;
use App\Support\InvoiceXpressIssueRepository;
use App\Support\InvoiceXpressIssueTerms;
use App\Support\InvoiceXpressReceipt;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class InvoiceXpressReceiptPage extends InvoiceXpressSectionPage implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'recibos-invoicexpress';

    protected string $view = 'filament.pages.invoice-xpress-receipt';

    #[Locked]
    public int $accountId;

    #[Locked]
    public ?int $invoiceId = null;

    /** @var array{allocationId: int, paymentId: int, charge: string, invoice: string, paidOn: string, amount: int, series: string, revision: string, withholding?: array, canIssue?: bool}|null */
    #[Locked]
    public ?array $previewData = null;

    public bool $accepted = false;

    private ?bool $writeEnabled = null;

    public static function canAccess(): bool
    {
        return InvoiceXpressIssueRepository::ownerCanIssue();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->accountId = CurrentAccount::id();

        if (request()->query->has('invoice')) {
            $invoiceId = InvoiceXpressIssueTerms::positiveId(request()->query('invoice'));
            abort_unless($invoiceId !== null && FiscalDocument::query()->whereKey($invoiceId)
                ->whereIn('type', [FiscalDocumentType::Invoice, FiscalDocumentType::InvoiceReceipt])
                ->where('direction', FiscalDocumentDirection::Issued)
                ->where('status', FiscalDocumentStatus::Active)
                ->where('currency', 'EUR')
                ->whereHas('links', fn (Builder $query) => $query->whereNotNull('receivable_id'))
                ->exists(), 404);
            $this->invoiceId = $invoiceId;
        }
    }

    public function table(Table $table): Table
    {
        $this->authorizeAccount();

        $invoiceLinks = FiscalDocumentLink::query()->whereNotNull('receivable_id')
            ->when($this->invoiceId !== null, fn (Builder $query) => $query->where('fiscal_document_id', $this->invoiceId))
            ->whereHas('fiscalDocument', fn (Builder $documents): Builder => $documents
                ->whereIn('type', [FiscalDocumentType::Invoice, FiscalDocumentType::InvoiceReceipt])
                ->where('direction', FiscalDocumentDirection::Issued)
                ->where('status', FiscalDocumentStatus::Active)
                ->where('currency', 'EUR'));
        $providerInvoiceLinks = (clone $invoiceLinks)->whereNull('related_fiscal_document_id')
            ->whereHas('fiscalDocument', fn (Builder $documents) => $documents->where('type', FiscalDocumentType::Invoice)
                ->whereIn('id', InvoiceXpressSnapshot::query()->whereNotNull('fiscal_document_id')->select('fiscal_document_id')));
        $hasConnection = InvoiceXpressConfig::current() !== null;

        return $table
            ->query(PaymentAllocation::query()
                ->whereIn('receivable_id', $invoiceLinks->select('receivable_id'))
                ->whereHas('payment', fn (Builder $query) => $query->whereNull('advance_client_id'))
                ->with('receivable.billable')
                ->withExists(['receivable as can_preview_provider' => fn (Builder $query) => $query
                    ->whereIn('receivables.id', $providerInvoiceLinks->select('receivable_id'))
                    ->whereDoesntHave('payments', fn (Builder $payments) => $payments->whereNotNull('payments.advance_client_id'))])
                ->addSelect(['receipt_attempt_id' => InvoiceXpressReceiptAttempt::query()->select('id')
                    ->where(fn (Builder $query) => $query->whereColumn('payment_allocation_id', 'payment_allocations.id')
                        ->orWhere(fn (Builder $query) => $query->whereColumn('payment_id', 'payment_allocations.payment_id')
                            ->whereColumn('receivable_id', 'payment_allocations.receivable_id')))
                    ->limit(1)]))
            ->defaultSort('allocated_on', 'desc')
            ->columns([
                TextColumn::make('receivable.description')->label(__('Charge'))->searchable()->wrap()
                    ->state(fn (PaymentAllocation $record): ?string => $record->receivable?->label),
                TextColumn::make('payment_id')->label(__('Payment received'))->formatStateUsing(fn (int $state): string => '#'.$state),
                TextColumn::make('allocated_on')->label(__('Date'))->date(fn (): string => Language::resolve(app()->getLocale())->dateFormat())->sortable(),
                TextColumn::make('amount')->label(__('Allocated amount'))->formatStateUsing(fn (int $state): string => Money::format($state, locale: app()->getLocale())),
                TextColumn::make('receipt_attempt_id')->label(__('Attempt'))
                    ->formatStateUsing(fn (int $state): string => '#'.$state)->placeholder('—'),
            ])
            ->recordActions([
                Action::make('reconcileWithholding')
                    ->label(__('Reconcile withholding'))
                    ->visible(fn (): bool => WithholdingReviewPage::canRecord())
                    ->url(fn (PaymentAllocation $record): string => WithholdingReviewPage::getUrl(['allocation' => $record->id])),
                Action::make('reconcileReceipt')
                    ->label(__('Reconcile receipt'))
                    ->visible(fn (): bool => WithholdingReviewPage::canRecord())
                    ->url(fn (PaymentAllocation $record): string => WithholdingReviewPage::getUrl(['allocation' => $record->id, 'ordinary' => true])),
                Action::make('preview')
                    ->label(__('Preview receipt'))
                    ->visible(fn (PaymentAllocation $record): bool => $hasConnection && $record->receipt_attempt_id === null && $record->can_preview_provider)
                    ->action(fn (PaymentAllocation $record) => $this->preview($record->id, app(InvoiceXpressReceipt::class))),
            ])
            ->emptyStateHeading(__('No payments allocated to invoices'));
    }

    public function preview(int $allocationId, InvoiceXpressReceipt $receipts): void
    {
        $this->authorizeAccount();
        $this->reset('accepted', 'previewData');
        $this->resetValidation();

        try {
            $this->previewData = $receipts->preview($allocationId);
        } catch (InvoiceXpressException $exception) {
            $this->addError('receipt', $exception->getMessage());
        }
    }

    public function confirm(InvoiceXpressReceipt $receipts): void
    {
        $this->authorizeAccount();
        $this->resetValidation();

        if ($this->previewData === null || ! $this->accepted) {
            throw ValidationException::withMessages(['accepted' => __('Preview the receipt and confirm the details before issuing it.')]);
        }

        try {
            $attempt = $receipts->confirm($this->previewData['allocationId'], $this->previewData['revision']);
        } catch (InvoiceXpressException $exception) {
            $this->addError('receipt', $exception->getMessage());

            return;
        } finally {
            $this->reset('accepted', 'previewData');
        }

        $this->flushCachedTableRecords();
        $this->notifyResult($attempt);
    }

    public function recover(int $attemptId, InvoiceXpressReceipt $receipts): void
    {
        $this->authorizeAccount();
        $this->resetValidation();
        try {
            $this->notifyResult($receipts->recover($attemptId));
        } catch (InvoiceXpressException $exception) {
            $this->addError('receipt', $exception->getMessage());
        }
    }

    protected function getViewData(): array
    {
        $this->authorizeAccount();

        return [
            'canWrite' => $this->canWrite(),
            'attempts' => InvoiceXpressReceiptAttempt::query()->latest('id')->paginate(10, pageName: 'receiptAttemptsPage'),
        ];
    }

    private function authorizeAccount(): void
    {
        abort_unless(static::canAccess() && CurrentAccount::id() === $this->accountId, 403);
    }

    private function canWrite(): bool
    {
        return $this->writeEnabled ??= InvoiceXpressIssue::canWriteDocuments();
    }

    private function notifyResult(InvoiceXpressReceiptAttempt $attempt): void
    {
        if ($attempt->provider_receipt_id !== null) {
            Notification::make()->title(__('Receipt verified in InvoiceXpress'))->success()->send();

            return;
        }

        Notification::make()->title(__('Receipt awaiting verification'))
            ->body(__('Check the history. Verification looks for the receipt on the original invoice.'))
            ->warning()->send();
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return __('Receipts');
    }

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('Receipts');
    }
}
