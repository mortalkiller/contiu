<?php

namespace App\Filament\Resources\FiscalDocuments\Pages;

use App\Filament\Pages\IntegrationsPage;
use App\Filament\Resources\Concerns\HasSaveBar;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Resources\FiscalDocuments\Schemas\FiscalDocumentForm;
use App\Models\FiscalDocument;
use App\Support\FiscalDocumentPdfs;
use App\Support\Invoicing\Invoicing;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;

class CreateFiscalDocument extends CreateRecord
{
    use HasSaveBar;

    protected static string $resource = FiscalDocumentResource::class;

    protected function handleRecordCreation(array $data): FiscalDocument
    {
        return app(FiscalDocumentPdfs::class)->saveManual(FiscalDocumentForm::data($data));
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }

    public function getTitle(): string
    {
        return __('Record document');
    }

    /** Without a provider the app only records documents: it says so, and where to connect one. */
    public function getSubheading(): ?string
    {
        return Invoicing::connected()
            ? __('For documents issued in another program or received. Contiu keeps them, ties them to charges and counts them for tax.')
            : __('Without an invoicing provider connected, Contiu does not issue invoices: it only records them. Connect one to create and issue them here.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('connectProvider')
                ->label(__('Connect provider'))
                ->color('gray')
                ->visible(fn (): bool => ! Invoicing::connected() && IntegrationsPage::canAccess())
                ->url(fn (): string => IntegrationsPage::getUrl()),
        ];
    }
}
