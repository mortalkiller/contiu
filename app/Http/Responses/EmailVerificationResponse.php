<?php

namespace App\Http\Responses;

use App\Filament\Pages\Auth\EmailVerificationPrompt;
use Filament\Auth\Http\Responses\Contracts\EmailVerificationResponse as Responsable;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

/**
 * After the confirmation link: "Email confirmed" (EmailVerificationPrompt) instead of going
 * straight into the panel. That page goes on to where the user was heading.
 */
class EmailVerificationResponse implements Responsable
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        return redirect()
            ->to(Filament::getEmailVerificationPromptUrl())
            ->with(EmailVerificationPrompt::CONFIRMED_SESSION_KEY, true);
    }
}
