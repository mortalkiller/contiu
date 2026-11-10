<?php

namespace App\Filament\Resources\FiscalDocuments\Pages;

use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Models\FiscalDocument;
use App\Support\FiscalDocumentPdfs;
use Filament\Resources\Pages\CreateRecord;

class CreateFiscalDocument extends CreateRecord
{
    protected static string $resource = FiscalDocumentResource::class;

    protected function handleRecordCreation(array $data): FiscalDocument
    {
        return app(FiscalDocumentPdfs::class)->saveManual($data);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
