<?php

namespace App\Filament\Resources\FiscalDocuments\Pages;

use App\Actions\CancelFiscalDocument;
use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Exceptions\InvoicingProviderException;
use App\Filament\Actions\RecordPaymentAction;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\FiscalDocuments\Actions\CreditApplicationActions;
use App\Filament\Resources\FiscalDocuments\Actions\CustomerRefundAction;
use App\Filament\Resources\FiscalDocuments\Actions\DebitNoteChargeAction;
use App\Filament\Resources\FiscalDocuments\Actions\FiscalDocumentEmailAction;
use App\Filament\Resources\FiscalDocuments\Actions\FiscalDocumentPdfActions;
use App\Filament\Resources\FiscalDocuments\Actions\InvoiceAdvanceAction;
use App\Filament\Resources\FiscalDocuments\Actions\RecordEuServiceAction;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Resources\FiscalDocuments\Schemas\FiscalDocumentInfolist;
use App\Filament\Resources\FiscalDocuments\Tables\FiscalDocumentsTable;
use App\Models\Expense;
use App\Support\CurrentAccount;
use App\Support\Invoicing\Invoicing;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use MortalKiller\FilamentPageHeader\Concerns\HasPageHeader;

/**
 * A fiscal document's own page: the document as it reads, and beside it how it was issued, what
 * it is tied to and what happened to it. An issued document is never edited here: it is cancelled
 * or corrected with a note.
 */
class ViewFiscalDocument extends ViewRecord
{
    use HasPageHeader;

    protected static string $resource = FiscalDocumentResource::class;

    /**
     * Everything the page shows, loaded with the document: no line, linked record or related
     * document asks the database on its own.
     */
    public const array RELATIONS = [
        ...FiscalDocumentsTable::RELATIONS,
        'lines.article',
        'client.structuredAddress',
        'links.receivable.payments',
        'links.receivable.billable.client',
        'links.expense.category',
        'links.relatedFiscalDocument.links.receivable.payments',
    ];

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(self::RELATIONS);
    }

    /** "Fiscal documents › FT 2026/15": the page being the document's own is said by its title. */
    public function getBreadcrumbs(): array
    {
        return array_slice(parent::getBreadcrumbs(), 0, -1, preserve_keys: true);
    }

    /** As in the mock: the menu of everything else, the PDF, then the one thing this kind of document most likely needs next. */
    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                $this->cancelDocument(),
                $this->discardDraft(),
                $this->acceptQuote(),
                CreditApplicationActions::apply(),
                CreditApplicationActions::reverse(),
                CustomerRefundAction::make(),
                DebitNoteChargeAction::make(),
                InvoiceAdvanceAction::make(),
                RecordEuServiceAction::make(),
            ])->label(__('More actions')),
            FiscalDocumentPdfActions::download()->label(__('PDF'))->icon(null)->color('gray'),
            FiscalDocumentEmailAction::make()->icon(null),
            FiscalDocumentPdfActions::retrieve()->color('gray')->icon(null)
                ->visible(fn (): bool => $this->record->currentPdf === null && ! $this->record->isDraft() && $this->record->integrationRecord !== null && Invoicing::connected()),
            Action::make('editRecord')
                ->label(__('Edit'))
                ->color('gray')
                ->visible(fn (): bool => $this->record->integrationRecord === null && ! $this->record->isDraft() && FiscalDocumentResource::canEdit($this->record))
                ->url(fn (): string => FiscalDocumentResource::getUrl('edit', ['record' => $this->record])),
            $this->nextStep(),
        ];
    }

    /**
     * What the document most likely needs next: finishing a draft, turning a quote into an
     * invoice or a pro forma into a fatura-recibo, paying a supplier's invoice, or recording
     * what the client paid.
     */
    private function nextStep(): Action
    {
        $document = $this->record;
        $receivable = $document->links?->receivable;
        $expense = $document->direction === FiscalDocumentDirection::Received ? $document->links?->expense : null;

        return match (true) {
            $document->isDraft() => Action::make('continue')
                ->label(__('Continue'))
                ->visible(fn (): bool => FiscalDocumentResource::canEdit($document))
                ->url(FiscalDocumentResource::getUrl('draft', ['record' => $document])),
            FiscalDocumentInfolist::canConvert($document) => $this->convertInto($this->conversionTarget()),
            $expense !== null => $this->expensePayment($expense),
            default => RecordPaymentAction::make()
                ->icon(null)
                ->record($receivable)
                ->visible(fn (): bool => $receivable !== null && $receivable->outstanding > 0
                    && $document->status === FiscalDocumentStatus::Active && $document->direction === FiscalDocumentDirection::Issued)
                ->after(fn () => $this->redirect(FiscalDocumentResource::getUrl('view', ['record' => $document]))),
        };
    }

    /** A quote becomes an invoice. A pro forma was a request for payment, so it becomes a fatura-recibo where the provider issues them. */
    private function conversionTarget(): FiscalDocumentType
    {
        return $this->record->type === FiscalDocumentType::ProForma
            && Invoicing::provider()?->capabilities()->supports(FiscalDocumentType::InvoiceReceipt)
                ? FiscalDocumentType::InvoiceReceipt
                : FiscalDocumentType::Invoice;
    }

    private function convertInto(FiscalDocumentType $target): Action
    {
        return Action::make('convert')
            ->label($target === FiscalDocumentType::InvoiceReceipt ? __('Convert into an invoice receipt') : __('Convert into an invoice'))
            ->url(FiscalDocumentResource::getUrl('compose', ['type' => $target->value, 'from' => $this->record->getKey()]));
    }

    /**
     * "Registar pagamento" on a supplier's invoice: its expense is marked as paid today, as on
     * the expenses list. No policy knows about read-only accounts, so that is checked here too.
     */
    private function expensePayment(Expense $expense): Action
    {
        return Action::make('payExpense')
            ->label(__('Record payment made'))
            ->visible(fn (): bool => ! $expense->is_paid && $this->record->status === FiscalDocumentStatus::Active && ExpenseResource::canEdit($expense))
            ->disabled(fn (): bool => CurrentAccount::readOnly())
            ->tooltip(fn (): ?string => CurrentAccount::readOnly() ? __('The account is read-only') : null)
            ->requiresConfirmation()
            ->modalDescription(__('The expense of this document is marked as paid today.'))
            ->action(function () use ($expense): void {
                abort_if(CurrentAccount::readOnly(), 403);
                ExpenseResource::authorizeEdit($expense);

                if (! $expense->refresh()->is_paid) {
                    $expense->update(['is_paid' => true, 'paid_on' => today()]);
                }

                Notification::make()->success()->title(__('Expense marked as paid'))->send();
                $this->redirect(FiscalDocumentResource::getUrl('view', ['record' => $this->record]));
            });
    }

    private function cancelDocument(): Action
    {
        return Action::make('cancel')
            ->label(__('Cancel document'))
            ->color('danger')
            ->visible(fn (): bool => app(CancelFiscalDocument::class)->canCancel($this->record))
            ->modalHeading(fn (): string => __('Cancel :number', ['number' => $this->record->number]))
            ->modalDescription(fn (): string => __('The document is cancelled at :provider too and stops counting. This cannot be undone.', ['provider' => Invoicing::providerLabel() ?? '']))
            ->modalSubmitActionLabel(__('Cancel document'))
            ->schema([
                TextInput::make('reason')->label(__('Reason'))->required()->maxLength(CancelFiscalDocument::MAX_REASON_LENGTH),
            ])
            ->action(function (array $data, Schema $schema): void {
                try {
                    app(CancelFiscalDocument::class)->handle($this->record, $data['reason']);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(Arr::prependKeysWith($exception->errors(), $schema->getStatePath().'.'));
                } catch (InvoicingProviderException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title(__('Document cancelled'))->send();
                $this->redirect(FiscalDocumentResource::getUrl('view', ['record' => $this->record]));
            });
    }

    private function discardDraft(): Action
    {
        return Action::make('discard')
            ->label(__('Delete draft'))
            ->color('danger')
            ->visible(fn (): bool => $this->record->isDraft() && Invoicing::canIssue())
            ->requiresConfirmation()
            ->modalDescription(fn (): string => __('The draft is removed here and at :provider.', ['provider' => Invoicing::providerLabel() ?? '']))
            ->action(function (): void {
                try {
                    app(CancelFiscalDocument::class)->discard($this->record);
                } catch (InvoicingProviderException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title(__('Draft deleted'))->send();
                $this->redirect(FiscalDocumentResource::getUrl('index'));
            });
    }

    /** A quote the client said yes to. It changes nothing fiscal; it only tells quotes apart. */
    private function acceptQuote(): Action
    {
        return Action::make('markAccepted')
            ->label(__('Mark as accepted'))
            ->visible(fn (): bool => $this->record->type === FiscalDocumentType::Quote && $this->record->status === FiscalDocumentStatus::Active
                && $this->record->accepted_at === null && FiscalDocumentResource::canEdit($this->record))
            ->action(function (): void {
                FiscalDocumentResource::authorizeEdit($this->record);
                $this->record->forceFill(['accepted_at' => now()])->save();
                Notification::make()->success()->title(__('Quote marked as accepted'))->send();
            });
    }
}
