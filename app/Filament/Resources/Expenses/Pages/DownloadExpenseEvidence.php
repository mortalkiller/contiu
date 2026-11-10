<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Expense;
use App\Support\ExpenseEvidence;
use Filament\Panel;
use Filament\Resources\Pages\PageRegistration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DownloadExpenseEvidence
{
    public static function route(string $path): PageRegistration
    {
        return new PageRegistration(
            page: self::class,
            route: fn (Panel $panel) => Route::get($path, self::class)
                ->whereNumber('record')
                ->middleware(ListExpenses::getRouteMiddleware($panel))
                ->withoutMiddleware(ListExpenses::getWithoutRouteMiddleware($panel)),
        );
    }

    public function __invoke(Request $request): StreamedResponse
    {
        $expense = ExpenseResource::resolveRecordRouteBinding($request->route('record'));
        abort_unless($expense instanceof Expense, 404);
        ExpenseResource::authorizeView($expense);

        $path = ExpenseEvidence::path($expense);
        $disk = Storage::disk(ExpenseEvidence::DISK);
        abort_if($path === null || ! $disk->exists($path), 404);

        return $disk->download($path, 'comprovativo-despesa-'.$expense->getKey().'.pdf', [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
