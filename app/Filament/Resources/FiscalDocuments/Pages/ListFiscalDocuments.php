<?php

namespace App\Filament\Resources\FiscalDocuments\Pages;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Filament\Pages\InvoiceXpressImport;
use App\Filament\Pages\InvoiceXpressIssuePage;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Resources\FiscalDocuments\Widgets\FiscalDocumentsSummary;
use App\Models\FiscalDocument;
use Filament\Actions\Action;
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
        return 'Faturas, recibos e notas de crédito, emitidos e recebidos.';
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
            'all' => Tab::make('Todos')->badge($count()),
            'issued' => Tab::make('Emitidos')->modifyQueryUsing($active(FiscalDocumentDirection::Issued))->badge($count($active(FiscalDocumentDirection::Issued))),
            'received' => Tab::make('Recebidos')->modifyQueryUsing($active(FiscalDocumentDirection::Received))->badge($count($active(FiscalDocumentDirection::Received))),
            'voided' => Tab::make('Anulados')->modifyQueryUsing($voided)->badge($count($voided))->badgeColor('gray'),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importFromInvoiceXpress')
                ->label('Importar do InvoiceXpress')
                ->link()
                ->color('gray')
                ->url(fn (): string => InvoiceXpressImport::getUrl())
                ->visible(fn (): bool => InvoiceXpressImport::canAccess()),
            Action::make('issueInvoice')
                ->label('Emitir fatura')
                ->color('gray')
                ->url(fn (): string => InvoiceXpressIssuePage::getUrl())
                ->visible(fn (): bool => InvoiceXpressIssuePage::canAccess()),
            CreateAction::make()->label('Registar documento'),
        ];
    }
}
