<?php

namespace App\Filament\Resources\FiscalDocuments\Pages;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Filament\Pages\FiscalDocumentDraftsPage;
use App\Filament\Pages\InvoiceXpressImport;
use App\Filament\Pages\InvoiceXpressIssuePage;
use App\Filament\Pages\InvoiceXpressNoteIssuePage;
use App\Filament\Pages\InvoiceXpressReceiptPage;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Resources\FiscalDocuments\Widgets\FiscalDocumentsSummary;
use App\Models\FiscalDocument;
use App\Support\FiscalProviderRegistry;
use App\Support\InvoiceXpressIssue;
use App\Support\InvoiceXpressIssueRepository;
use App\Support\InvoiceXpressNoteTerms;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListFiscalDocuments extends ListRecords
{
    protected static string $resource = FiscalDocumentResource::class;

    protected function getHeaderWidgets(): array
    {
        return [FiscalDocumentsSummary::class];
    }

    public function getSubheading(): ?string
    {
        return __('Invoices, receipts and credit notes, issued and received.');
    }

    /** Active documents by direction, with the voided ones apart: they stay out of every sum. */
    public function getTabs(): array
    {
        $active = fn (FiscalDocumentDirection $direction) => fn (Builder $query) => $query
            ->where('direction', $direction)
            ->where('status', FiscalDocumentStatus::Active);
        $voided = fn (Builder $query) => $query->where('status', FiscalDocumentStatus::Voided);
        $count = fn (?callable $scope = null) => ($scope ? $scope(FiscalDocument::query()) : FiscalDocument::query())->count() ?: null;

        return [
            'all' => Tab::make(__('All'))->badge($count()),
            'issued' => Tab::make(__('Issued fiscal documents'))->modifyQueryUsing($active(FiscalDocumentDirection::Issued))->badge($count($active(FiscalDocumentDirection::Issued))),
            'received' => Tab::make(__('Received fiscal documents'))->modifyQueryUsing($active(FiscalDocumentDirection::Received))->badge($count($active(FiscalDocumentDirection::Received))),
            'voided' => Tab::make(__('Voided fiscal documents'))->modifyQueryUsing($voided)->badge($count($voided))->badgeColor('gray'),
        ];
    }

    protected function getHeaderActions(): array
    {
        $provider = InvoiceXpressIssueRepository::ownerCanIssue()
            ? app(FiscalProviderRegistry::class)->current()
            : null;
        $hasInvoiceXpress = $provider?->provider() === 'invoicexpress';
        $types = $hasInvoiceXpress && InvoiceXpressIssue::options() !== null
            ? array_unique([...$provider->draftDocumentTypes(), ...array_keys(InvoiceXpressIssue::DocumentTypes + InvoiceXpressNoteTerms::Types)])
            : [];

        return [
            ActionGroup::make([
                Action::make('issueInvoice')
                    ->label(__('Invoice'))
                    ->url(fn (): string => InvoiceXpressIssuePage::getUrl(['type' => 'invoice']))
                    ->visible(in_array('invoice', $types, true)),
                Action::make('issueInvoiceReceipt')
                    ->label(__('Invoice receipt'))
                    ->url(fn (): string => InvoiceXpressIssuePage::getUrl(['type' => 'invoice_receipt']))
                    ->visible(in_array('invoice_receipt', $types, true)),
                Action::make('issueCreditNote')
                    ->label(__('Credit note'))
                    ->url(fn (): string => InvoiceXpressNoteIssuePage::getUrl(['type' => 'credit_note']))
                    ->visible(in_array('credit_note', $types, true)),
                Action::make('issueDebitNote')
                    ->label(__('Debit note'))
                    ->url(fn (): string => InvoiceXpressNoteIssuePage::getUrl(['type' => 'debit_note']))
                    ->visible(in_array('debit_note', $types, true)),
                Action::make('issueReceipt')
                    ->label(__('Receipt'))
                    ->url(fn (): string => InvoiceXpressReceiptPage::getUrl())
                    ->visible($hasInvoiceXpress),
            ])->label(__('New document'))->button()->color('primary'),
            Action::make('synchronize')
                ->label(__('Synchronize'))
                ->color('gray')
                ->url(fn (): string => InvoiceXpressImport::getUrl())
                ->visible($hasInvoiceXpress && InvoiceXpressImport::canAccess()),
            Action::make('drafts')
                ->label(__('Drafts'))
                ->color('gray')
                ->url(fn (): string => FiscalDocumentDraftsPage::getUrl())
                ->visible($hasInvoiceXpress),
            CreateAction::make()->label(__('Record document')),
        ];
    }
}
