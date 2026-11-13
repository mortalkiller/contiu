<?php

namespace App\Http\Controllers;

use App\Models\InvoiceBenchmarkDocument;
use App\Models\User;
use App\Support\CurrentAccount;
use App\Support\InvoiceBenchmark\InvoiceQrCodeService;
use Fruitcake\LaravelDebugbar\Facades\Debugbar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class InvoiceBenchmarkPreviewController extends Controller
{
    public function __invoke(Request $request, string $tenant, string $document, InvoiceQrCodeService $qr): Response
    {
        $user = $request->user();
        abort_unless(config('invoice-benchmark.enabled') && $user instanceof User && $user->isSuperAdmin(), 404);

        Debugbar::disable();
        $record = InvoiceBenchmarkDocument::query()->where('user_id', $user->id)->where('account_id', CurrentAccount::id())
            ->where('expires_at', '>', now())->findOrFail($document);
        abort_unless(is_file($record->localPath()), 404);

        $headers = [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];

        if ($record->mime === 'application/pdf') {
            $page = $request->integer('page', 1);
            abort_unless($page >= 1 && $page <= $record->dimensions['pages'], 404);
            try {
                $image = $qr->pdfPage($record, $page);
                $bytes = File::get($image);
            } catch (RuntimeException) {
                abort(422, 'The PDF preview could not be rendered.');
            } finally {
                if (isset($image)) {
                    File::deleteDirectory(dirname($image));
                }
            }

            return response($bytes, 200, [...$headers, 'Content-Type' => 'image/png']);
        }

        return new BinaryFileResponse($record->localPath(), headers: [...$headers, 'Content-Type' => $record->mime], public: false);
    }
}
