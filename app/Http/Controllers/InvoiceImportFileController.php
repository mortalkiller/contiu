<?php

namespace App\Http\Controllers;

use App\Filament\Pages\InvoiceImportInbox;
use App\Models\InvoiceImport;
use App\Modules\InvoiceReader\InvoiceQrCodeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Shows the private file of one invoice on "Importar faturas", to whoever may see the account's expenses. */
class InvoiceImportFileController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        abort_unless(InvoiceImportInbox::canAccess(), 403);

        // Scoped to the account in the address: another account's invoice is simply not found.
        $import = InvoiceImport::query()->findOrFail((int) $request->route('import'));
        $disk = Storage::disk(InvoiceImport::DISK);
        abort_unless(in_array($import->mime, InvoiceQrCodeService::MIME_TYPES, true) && $disk->exists($import->storagePath()), 404);

        return $disk->response($import->storagePath(), headers: [
            'Content-Type' => $import->mime,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
