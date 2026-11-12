<?php

namespace App\Filament\Pages\Auth;

use App\Filament\Pages\Auth\Concerns\UsesContiuAuthLayout;
use Filament\Actions\Action;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

/**
 * Sign-in, as in the "Autenticação" mocks. Filament's flow (rate limit, two-step challenge) is
 * unchanged; the challenge keeps Filament's own heading.
 */
class Login extends BaseLogin
{
    use UsesContiuAuthLayout;

    protected string $view = 'filament.pages.auth.auth-page';

    protected static string $layout = 'filament.pages.auth.auth-layout';

    public function getTitle(): string|Htmlable
    {
        return __('Sign in');
    }

    public function getHeading(): string|Htmlable|null
    {
        if ($this->isUndertakingMultiFactorAuthentication()) {
            return parent::getHeading();
        }

        return __('Sign in to Contiu');
    }

    public function getSubheading(): string|Htmlable|null
    {
        if ($this->isUndertakingMultiFactorAuthentication()) {
            return parent::getSubheading();
        }

        return __('Good to see you. Pick up where you left off.');
    }

    protected function getEmailFormComponent(): Component
    {
        return parent::getEmailFormComponent()->label(__('Email'));
    }

    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()
            ->hint(filament()->hasPasswordReset()
                ? new HtmlString(Blade::render('<x-filament::link :href="filament()->getRequestPasswordResetUrl()" tabindex="-1">{{ __(\'Forgot it?\') }}</x-filament::link>'))
                : null);
    }

    protected function getRememberFormComponent(): Component
    {
        return parent::getRememberFormComponent()->label(__('Keep me signed in on this device'));
    }

    protected function getAuthenticateFormAction(): Action
    {
        return parent::getAuthenticateFormAction()->label(__('Sign in'));
    }

    protected function getClosingLine(): string|Htmlable|null
    {
        if ($this->isUndertakingMultiFactorAuthentication() || ! filament()->hasRegistration()) {
            return null;
        }

        return new HtmlString(e(__('No account yet?')).' '.Blade::render(
            '<x-filament::link :href="$url">{{ $label }}</x-filament::link>',
            ['url' => filament()->getRegistrationUrl(), 'label' => __('Start with :days days free', ['days' => config('contiu.trial_days')])],
        ));
    }

    private function isUndertakingMultiFactorAuthentication(): bool
    {
        return filled($this->userUndertakingMultiFactorAuthentication);
    }
}
