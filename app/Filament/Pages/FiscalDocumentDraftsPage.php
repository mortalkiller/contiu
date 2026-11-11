<?php

namespace App\Filament\Pages;

use App\Exceptions\InvoiceXpressException;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Models\FiscalDocumentDraft;
use App\Support\CurrentAccount;
use App\Support\FiscalProviderRegistry;
use App\Support\InvoiceXpressDraftDelivery;
use App\Support\InvoiceXpressDraftSync;
use App\Support\InvoiceXpressIssue;
use App\Support\InvoiceXpressIssueRepository;
use App\Support\InvoiceXpressNoteTerms;
use App\Support\InvoiceXpressSandboxDrafts;
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

class FiscalDocumentDraftsPage extends InvoiceXpressSectionPage implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'documentos-fiscais/rascunhos';

    protected string $view = 'filament.pages.fiscal-document-drafts';

    #[Locked]
    public int $accountId;

    public static function canAccess(): bool
    {
        return InvoiceXpressIssueRepository::ownerCanIssue()
            && app(FiscalProviderRegistry::class)->current()?->provider() === 'invoicexpress';
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->accountId = CurrentAccount::id();
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return __('Drafts');
    }

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('Drafts');
    }

    /** @return Builder<FiscalDocumentDraft> */
    #[Unexposed]
    public static function draftQuery(): Builder
    {
        abort_unless(InvoiceXpressIssueRepository::ownerCanIssue(), 403);
        $provider = app(FiscalProviderRegistry::class)->current();
        abort_unless($provider?->provider() === 'invoicexpress', 404);

        return FiscalDocumentDraft::query()
            ->where('provider', $provider->provider())
            ->where('provider_account', $provider->providerAccount());
    }

    #[Unexposed]
    public static function statusLabel(FiscalDocumentDraft $draft): string
    {
        return match ($draft->state) {
            FiscalDocumentDraft::Local => __('Saved in Contiu'),
            FiscalDocumentDraft::Sending => __('InvoiceXpress send pending'),
            FiscalDocumentDraft::Remote => __('Draft in InvoiceXpress'),
            FiscalDocumentDraft::Promoted => __('Imported as fiscal document'),
            default => __('Draft needs review'),
        };
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function (): Builder {
                $this->authorizeAccount();

                return static::draftQuery()->with('fiscalDocument');
            })
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label(__('Draft'))->prefix('#'),
                TextColumn::make('document_type')->label(__('Document type'))
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'credit_note' => __('Credit note'),
                        'debit_note' => __('Debit note'),
                        default => __(InvoiceXpressIssue::DocumentTypes[$state] ?? 'Document'),
                    }),
                TextColumn::make('state')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (FiscalDocumentDraft $record): string => static::statusLabel($record))
                    ->color(fn (string $state): string => match ($state) {
                        FiscalDocumentDraft::Promoted => 'success',
                        FiscalDocumentDraft::Remote => 'info',
                        FiscalDocumentDraft::Blocked, FiscalDocumentDraft::Sending => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('remote_id')->label(__('InvoiceXpress draft ID'))->placeholder('—'),
            ])
            ->recordActions([
                Action::make('open')
                    ->label(fn (FiscalDocumentDraft $record): string => $record->state === FiscalDocumentDraft::Local ? __('Edit') : __('View draft'))
                    ->visible(fn (FiscalDocumentDraft $record): bool => $record->state !== FiscalDocumentDraft::Promoted)
                    ->url(fn (FiscalDocumentDraft $record): string => array_key_exists($record->document_type, InvoiceXpressNoteTerms::Types)
                        ? InvoiceXpressNoteIssuePage::getUrl(['draft' => $record->id])
                        : InvoiceXpressIssuePage::getUrl(['draft' => $record->id])),
                Action::make('viewDocument')
                    ->label(__('View document'))
                    ->visible(fn (FiscalDocumentDraft $record): bool => $record->state === FiscalDocumentDraft::Promoted
                        && $record->fiscalDocument !== null && FiscalDocumentResource::canEdit($record->fiscalDocument))
                    ->url(fn (FiscalDocumentDraft $record): ?string => $record->fiscalDocument === null
                        ? null
                        : FiscalDocumentResource::getUrl('edit', ['record' => $record->fiscalDocument])),
                Action::make('send')
                    ->label(fn (FiscalDocumentDraft $record): string => $record->state === FiscalDocumentDraft::Sending ? __('Retry sending draft') : __('Send draft to InvoiceXpress'))
                    ->visible(fn (FiscalDocumentDraft $record): bool => $this->canSend($record))
                    ->requiresConfirmation()
                    ->action(function (FiscalDocumentDraft $record): void {
                        $this->authorizeAccount();
                        $draft = static::draftQuery()->findOrFail($record->id);
                        abort_unless($this->canSend($draft), 403);

                        $this->runDraftAction(fn (): FiscalDocumentDraft => app(InvoiceXpressDraftDelivery::class)->send($draft->id));
                    }),
                Action::make('refresh')
                    ->label(__('Refresh draft status'))
                    ->visible(fn (FiscalDocumentDraft $record): bool => $this->canRefresh($record))
                    ->action(function (FiscalDocumentDraft $record): void {
                        $this->authorizeAccount();
                        $draft = static::draftQuery()->findOrFail($record->id);
                        abort_unless($this->canRefresh($draft), 403);

                        $this->runDraftAction(fn (): FiscalDocumentDraft => app(InvoiceXpressDraftSync::class)->refresh($draft->id));
                    }),
            ])
            ->emptyStateHeading(__('No drafts'));
    }

    private function authorizeAccount(): void
    {
        abort_unless($this->accountId === CurrentAccount::get()?->getKey() && static::canAccess(), 403);
    }

    private function canSend(FiscalDocumentDraft $draft): bool
    {
        return in_array($draft->state, [FiscalDocumentDraft::Local, FiscalDocumentDraft::Sending], true)
            && $draft->remote_id === null
            && $draft->fiscal_document_id === null
            && app(FiscalProviderRegistry::class)->current()?->canCreateRemoteDraft($draft->document_type) === true
            && (($draft->preview['draftAccount'] ?? null) === $draft->provider_account || ($draft->preview['draftOnly'] ?? false) === true)
            && ($draft->preview['sequence']['name'] ?? null) === InvoiceXpressSandboxDrafts::Series;
    }

    private function canRefresh(FiscalDocumentDraft $draft): bool
    {
        return $draft->remote_id > 0
            && in_array($draft->state, [FiscalDocumentDraft::Remote, FiscalDocumentDraft::Blocked], true);
    }

    private function runDraftAction(callable $action): void
    {
        try {
            $draft = $action();
            $notice = Notification::make()->title(static::statusLabel($draft));
            match ($draft->state) {
                FiscalDocumentDraft::Blocked, FiscalDocumentDraft::Sending => $notice->warning(),
                default => $notice->success(),
            };
            $notice->send();
        } catch (ValidationException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();
        } catch (InvoiceXpressException) {
            Notification::make()->title(__('Unable to update the draft in InvoiceXpress.'))->danger()->send();
        }
    }
}
