<?php

namespace App\Filament\Pages;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Exceptions\InvoiceXpressException;
use App\Models\FiscalDocumentLink;
use App\Models\InvoiceXpressReceiptAttempt;
use App\Models\InvoiceXpressSnapshot;
use App\Models\PaymentAllocation;
use App\Support\CurrentAccount;
use App\Support\InvoiceXpressIssue;
use App\Support\InvoiceXpressIssueRepository;
use App\Support\InvoiceXpressReceipt;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
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

    protected static ?string $title = 'Recibos InvoiceXpress';

    protected static ?string $slug = 'recibos-invoicexpress';

    protected string $view = 'filament.pages.invoice-xpress-receipt';

    #[Locked]
    public int $accountId;

    /** @var array{allocationId: int, paymentId: int, charge: string, invoice: string, paidOn: string, amount: int, series: string, revision: string}|null */
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
    }

    public function table(Table $table): Table
    {
        $this->authorizeAccount();

        $invoiceLinks = FiscalDocumentLink::query()
            ->whereHas('fiscalDocument', fn (Builder $documents): Builder => $documents
                ->where('type', FiscalDocumentType::Invoice)
                ->where('direction', FiscalDocumentDirection::Issued)
                ->where('status', FiscalDocumentStatus::Active)
                ->whereIn('id', InvoiceXpressSnapshot::query()->whereNotNull('fiscal_document_id')->select('fiscal_document_id')));

        return $table
            ->query(PaymentAllocation::query()
                ->whereIn('receivable_id', $invoiceLinks->whereNotNull('receivable_id')->select('receivable_id'))
                ->with('receivable.billable')
                ->addSelect(['receipt_attempt_id' => InvoiceXpressReceiptAttempt::query()->select('id')
                    ->where(fn (Builder $query) => $query->whereColumn('payment_allocation_id', 'payment_allocations.id')
                        ->orWhere(fn (Builder $query) => $query->whereColumn('payment_id', 'payment_allocations.payment_id')
                            ->whereColumn('receivable_id', 'payment_allocations.receivable_id')))
                    ->limit(1)]))
            ->defaultSort('allocated_on', 'desc')
            ->columns([
                TextColumn::make('receivable.description')->label('Cobrança')->searchable()->wrap()
                    ->state(fn (PaymentAllocation $record): ?string => $record->receivable?->label),
                TextColumn::make('payment_id')->label('Recebimento')->formatStateUsing(fn (int $state): string => '#'.$state),
                TextColumn::make('allocated_on')->label('Data')->date('d/m/Y')->sortable(),
                TextColumn::make('amount')->label('Montante afeto')->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('receipt_attempt_id')->label('Tentativa')
                    ->formatStateUsing(fn (int $state): string => '#'.$state)->placeholder('—'),
            ])
            ->recordActions([
                Action::make('reconcileWithholding')
                    ->label('Conciliar retenção')
                    ->visible(fn (): bool => WithholdingReviewPage::canRecord())
                    ->url(fn (PaymentAllocation $record): string => WithholdingReviewPage::getUrl(['allocation' => $record->id])),
                Action::make('preview')
                    ->label('Pré-visualizar recibo')
                    ->visible(fn (PaymentAllocation $record): bool => $record->receipt_attempt_id === null && $this->canWrite())
                    ->action(fn (PaymentAllocation $record) => $this->preview($record->id, app(InvoiceXpressReceipt::class))),
            ])
            ->emptyStateHeading('Sem afetações a faturas InvoiceXpress');
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
            throw ValidationException::withMessages(['accepted' => 'Pré-visualiza o recibo e confirma os dados antes de emitir.']);
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
            Notification::make()->title('Recibo verificado no InvoiceXpress')->success()->send();

            return;
        }

        Notification::make()->title('Recibo por verificar')
            ->body('Consulta o histórico. A verificação procura o recibo na fatura original.')
            ->warning()->send();
    }
}
