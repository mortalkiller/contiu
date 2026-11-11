<?php

namespace App\Filament\Resources\FiscalDocuments\Pages;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Filament\Pages\InvoicingSyncPage;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Resources\FiscalDocuments\Tables\FiscalDocumentsTable;
use App\Filament\Resources\FiscalDocuments\Widgets\FiscalDocumentsSummary;
use App\Models\FiscalDocument;
use App\Support\FinanceSettings;
use App\Support\Invoicing\Invoicing;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use pxlrbt\FilamentExcel\Actions\ExportAction;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class ListFiscalDocuments extends ListRecords
{
    protected static string $resource = FiscalDocumentResource::class;

    /** The list is the top of its section: no trail above the title. */
    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getHeaderWidgets(): array
    {
        return [FiscalDocumentsSummary::class];
    }

    public function getSubheading(): ?string
    {
        $provider = Invoicing::providerLabel();

        return $provider === null
            ? __('Invoices, receipts, notes and quotes issued in another program and recorded here.')
            : __('Invoices, receipts, notes and quotes. Contiu keeps the document; :provider issues it.', ['provider' => $provider]);
    }

    /**
     * One query counts every tab. A draft, a quote and a voided document each have their own tab
     * and stay out of "issued", which is what was actually billed or received.
     */
    public function getTabs(): array
    {
        $counts = FiscalDocument::query()->toBase()
            ->selectRaw('COUNT(*) AS everything')
            ->selectRaw('SUM(CASE WHEN status = ? AND direction = ? AND type NOT IN (?, ?) THEN 1 ELSE 0 END) AS issued', [FiscalDocumentStatus::Active->value, FiscalDocumentDirection::Issued->value, FiscalDocumentType::Quote->value, FiscalDocumentType::ProForma->value])
            ->selectRaw('SUM(CASE WHEN status = ? AND direction = ? THEN 1 ELSE 0 END) AS received', [FiscalDocumentStatus::Active->value, FiscalDocumentDirection::Received->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS drafts', [FiscalDocumentStatus::Draft->value])
            ->selectRaw('SUM(CASE WHEN status = ? AND type IN (?, ?) THEN 1 ELSE 0 END) AS quotes', [FiscalDocumentStatus::Active->value, FiscalDocumentType::Quote->value, FiscalDocumentType::ProForma->value])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS voided', [FiscalDocumentStatus::Voided->value])
            ->first();
        $badge = fn (string $key): ?int => ((int) $counts->{$key}) ?: null;
        $quoteTypes = [FiscalDocumentType::Quote, FiscalDocumentType::ProForma];

        return [
            'all' => Tab::make(__('All'))->badge($badge('everything')),
            'issued' => Tab::make(__('Issued fiscal documents'))->badge($badge('issued'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', FiscalDocumentStatus::Active)->where('direction', FiscalDocumentDirection::Issued)->whereNotIn('type', $quoteTypes)),
            'received' => Tab::make(__('Received fiscal documents'))->badge($badge('received'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', FiscalDocumentStatus::Active)->where('direction', FiscalDocumentDirection::Received)),
            'drafts' => Tab::make(__('Drafts'))->badge($badge('drafts'))->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', FiscalDocumentStatus::Draft)),
            'quotes' => Tab::make(__('Quotes'))->badge($badge('quotes'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', FiscalDocumentStatus::Active)->whereIn('type', $quoteTypes)),
            'voided' => Tab::make(__('Voided fiscal documents'))->badge($badge('voided'))->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', FiscalDocumentStatus::Voided)),
        ];
    }

    protected function getHeaderActions(): array
    {
        $canIssue = Invoicing::canIssue() && Invoicing::connected();

        return [
            Action::make('articles')
                ->label(__('Articles'))
                ->link()
                ->color('gray')
                ->url(fn (): string => ArticleResource::getUrl())
                ->visible(fn (): bool => $canIssue),
            Action::make('synchronisation')
                ->label(__('Synchronisation'))
                ->color('gray')
                ->url(fn (): string => InvoicingSyncPage::getUrl())
                ->visible(fn (): bool => $canIssue && InvoicingSyncPage::canAccess()),
            ExportAction::make()->label(__('Export to Excel'))->exports([FiscalDocumentsTable::excelExport()]),
            $canIssue ? $this->newDocumentMenu() : $this->recordDocument()->button(),
        ];
    }

    /** "New document": one entry per kind the provider issues, and recording one that came from elsewhere. */
    private function newDocumentMenu(): ActionGroup
    {
        $capabilities = Invoicing::provider()->capabilities();
        $types = array_filter(
            FiscalDocumentType::creatable(app(FinanceSettings::class)->sellsGoods()),
            fn (FiscalDocumentType $type): bool => $capabilities->supports($type),
        );

        return ActionGroup::make([
            ...array_map(fn (FiscalDocumentType $type): Action => Action::make("new_{$type->value}")
                ->label($type->getLabel())
                ->url(FiscalDocumentResource::getUrl('compose', ['type' => $type->value])), $types),
            $this->recordDocument()->label(__('Record a document issued elsewhere')),
        ])->label(__('New document'))->icon(Phosphor::Plus)->button()->color('primary');
    }

    private function recordDocument(): Action
    {
        return Action::make('record')
            ->label(__('Record document'))
            ->url(fn (): string => FiscalDocumentResource::getUrl('create'))
            ->visible(fn (): bool => FiscalDocumentResource::canCreate());
    }
}
