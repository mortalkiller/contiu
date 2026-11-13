<?php

namespace App\Http\Controllers;

use App\Enums\Language;
use App\Http\Middleware\ApplyGuestLanguage;
use App\Support\PanelLocation;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The language switch in the footer of the sign-in pages: kept in the session and back to the
 * page it was picked on (only a page of Contiu itself, never another site).
 */
class SetGuestLanguageController extends Controller
{
    public function __invoke(Request $request, Language $language): RedirectResponse
    {
        $request->session()->put(ApplyGuestLanguage::SESSION_KEY, $language->value);

        $previous = url()->previous();

        return redirect()->to(str_starts_with($previous, PanelLocation::url().'/') ? $previous : Filament::getPanel('admin')->getLoginUrl());
    }
}
