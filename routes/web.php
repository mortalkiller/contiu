<?php

use App\Http\Controllers\SetGuestLanguageController;
use App\Support\MailPreviews\MailPreviewRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;

// The Filament panel lives at the root ("/" is the dashboard, "/login" the sign-in page). The only
// public pages of its own are the legal texts linked from the sign-up (both in reserved_slugs).
Route::view('termos', 'legal.terms')->name('legal.terms');
Route::view('privacidade', 'legal.privacy')->name('legal.privacy');

// The language of the sign-in pages, before anyone signs in (their footer).
Route::get('idioma/{language}', SetGuestLanguageController::class)->name('guest-language');

require __DIR__.'/calendar-subscriptions.php';

/*
 * Email previews (/dev/mail-preview): every email Contiu sends, rendered with example data, and a
 * test send. Only on a local machine (and in the tests): no menu leads here, and the routes do not
 * exist anywhere else.
 */
if (app()->environment(['local', 'testing'])) {
    Route::prefix('dev/mail-preview')->name('dev.mail-preview.')->group(function (): void {
        Route::get('/', function (Request $request, MailPreviewRegistry $previews) {
            $template = $previews->normalizeTemplate($request->string('template')->toString() ?: null);
            $locale = $previews->normalizeLocale($request->string('locale')->toString() ?: null);
            $theme = $request->string('theme')->toString() === 'dark' ? 'dark' : 'light';

            return view('dev.mail-preview.index', [
                'templates' => collect($previews->templateOptions())
                    ->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label, 'url' => route('dev.mail-preview.render', $key, absolute: false)])
                    ->values()
                    ->all(),
                'localeOptions' => $previews->localeOptions(),
                'themeOptions' => $previews->themeOptions(),
                'selectedTemplate' => $template,
                'selectedLocale' => $locale,
                'selectedTheme' => $theme,
                'selectedSubject' => $previews->preview($template, $locale)->subject,
                'defaultRecipient' => (string) $request->user()?->email,
                'metadataUrl' => route('dev.mail-preview.metadata', absolute: false),
                'sendUrl' => route('dev.mail-preview.send', absolute: false),
            ]);
        })->name('index');

        Route::get('/metadata', fn (Request $request, MailPreviewRegistry $previews) => response()->json([
            'subject' => $previews->preview($request->string('template')->toString(), $request->string('locale')->toString())->subject,
        ]))->name('metadata');

        Route::get('/render/{template}', fn (Request $request, MailPreviewRegistry $previews, string $template) => response(
            $previews->preview($template, $request->string('locale')->toString())->html,
        ))->name('render');

        Route::post('/send', function (Request $request, MailPreviewRegistry $previews) {
            $data = $request->validate([
                'template' => ['required', 'string', Rule::in(array_keys($previews->templateOptions()))],
                'locale' => ['required', 'string', Rule::in(array_keys($previews->localeOptions()))],
                'email' => ['required', 'email:rfc'],
            ]);

            $preview = $previews->preview($data['template'], $data['locale']);
            $preview->sendTo($data['email']);

            return response()->json(['message' => 'Sent to '.$data['email'].'.', 'subject' => $preview->subject]);
        })->name('send');
    });
}

// With public sign-up closed, Filament has no /register: a 404, not the panel's "/{account}" route
// sending the visitor to sign in.
if (! config('contiu.registration_open')) {
    Route::get('register', fn () => abort(404));
}
