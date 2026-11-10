<?php

namespace App\Filament\Resources\FiscalDocuments\Pages;

use App\Filament\Resources\FiscalDocuments\Actions\FiscalDocumentPdfActions;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Resources\FiscalDocuments\Schemas\FiscalDocumentForm;
use App\Models\FiscalDocument;
use App\Support\FiscalDocumentPdfs;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditFiscalDocument extends EditRecord
{
    protected static string $resource = FiscalDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            FiscalDocumentPdfActions::download(),
            FiscalDocumentPdfActions::retrieve(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return FiscalDocumentForm::withLinkData($data, $this->getRecord());
    }

    protected function handleRecordUpdate(Model $record, array $data): FiscalDocument
    {
        return $this->record = app(FiscalDocumentPdfs::class)->saveManual($data, $record);
    }

    protected function afterSave(): void
    {
        $this->form->model($this->getRecord());
        $this->refreshFormData(['pdf_upload']);
    }
}
