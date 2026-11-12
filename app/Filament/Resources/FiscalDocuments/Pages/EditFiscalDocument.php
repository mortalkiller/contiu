<?php

namespace App\Filament\Resources\FiscalDocuments\Pages;

use App\Filament\Resources\Concerns\HasSaveBar;
use App\Filament\Resources\FiscalDocuments\Actions\FiscalDocumentPdfActions;
use App\Filament\Resources\FiscalDocuments\Actions\RecordEuServiceAction;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Resources\FiscalDocuments\Schemas\FiscalDocumentForm;
use App\Models\FiscalDocument;
use App\Support\FiscalDocumentPdfs;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditFiscalDocument extends EditRecord
{
    use HasSaveBar;

    protected static string $resource = FiscalDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            FiscalDocumentPdfActions::download(),
            FiscalDocumentPdfActions::retrieve(),
            RecordEuServiceAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return FiscalDocumentForm::withLines(FiscalDocumentForm::withLinkData($data, $this->getRecord()), $this->getRecord());
    }

    protected function handleRecordUpdate(Model $record, array $data): FiscalDocument
    {
        return $this->record = app(FiscalDocumentPdfs::class)->saveManual(FiscalDocumentForm::data($data), $record);
    }

    protected function afterSave(): void
    {
        $this->form->model($this->getRecord());
        $this->refreshFormData(['pdf_upload', 'net_amount', 'vat_amount', 'total_amount']);
    }
}
