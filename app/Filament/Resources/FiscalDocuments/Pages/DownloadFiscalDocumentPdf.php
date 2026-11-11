<?php

namespace App\Filament\Resources\FiscalDocuments\Pages;

use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Models\FiscalDocument;
use App\Support\FiscalDocumentPdfs;
use Filament\Panel;
use Filament\Resources\Pages\PageRegistration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DownloadFiscalDocumentPdf
{
    public static function route(string $path): PageRegistration
    {
        return new PageRegistration(
            page: self::class,
            route: fn (Panel $panel) => Route::get($path, self::class)
                ->whereNumber(['record', 'version'])
                ->middleware(ListFiscalDocuments::getRouteMiddleware($panel))
                ->withoutMiddleware(ListFiscalDocuments::getWithoutRouteMiddleware($panel)),
        );
    }

    public function __invoke(Request $request): StreamedResponse
    {
        $document = FiscalDocumentResource::resolveRecordRouteBinding($request->route('record'));
        abort_unless($document instanceof FiscalDocument, 404);
        FiscalDocumentResource::authorizeView($document);

        $versionId = $request->route('version');
        $pdf = $versionId === null ? $document->currentPdf : $document->pdfVersions()->find($versionId);
        $path = $pdf?->path();
        $disk = Storage::disk(FiscalDocumentPdfs::DISK);
        abort_if($path === null || ! $disk->exists($path), 404);

        return $disk->download($path, str(__('Fiscal document'))->slug().'-'.$document->getKey().'.pdf', [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
