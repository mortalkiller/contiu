<?php

namespace App\Filament\Pages\Auth;

use App\Filament\Pages\Auth\Concerns\UsesContiuAuthLayout;
use App\Support\TrustedDevice;
use Filament\Actions\Action;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\MultiFactor\MultiFactorChallenge;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Exposed;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

/**
 * Sign-in, as in the "Autenticação" mocks. Filament's flow (rate limit, two-step challenge) is
 * unchanged; the challenge keeps Filament's own heading and adds "Não pedir de novo neste
 * dispositivo durante 30 dias" (TrustedDevice).
 */
class Login extends BaseLogin
{
    use UsesContiuAuthLayout;

    protected string $view = 'filament.pages.auth.auth-page';

    protected static string $layout = 'filament.pages.auth.auth-layout';

    /**
     * Filament's sign-in; after a two-step challenge passed with "do not ask again" ticked, this
     * device is trusted for TrustedDevice::DAYS days.
     */
    #[Exposed]
    public function authenticate(): ?LoginResponse
    {
        $isAnsweringChallenge = $this->isUndertakingMultiFactorAuthentication();
        $trustsDevice = (bool) ($this->data['multiFactor']['trustDevice'] ?? false);

        $response = parent::authenticate();

        if ($response !== null && $isAnsweringChallenge && $trustsDevice && ($user = Filament::auth()->user()) !== null) {
            app(TrustedDevice::class)->trust($user);
        }

        return $response;
    }

    public function defaultMultiFactorChallengeForm(Schema $schema): Schema
    {
        return $schema
            ->components(function (): array {
                $user = $this->getUserUndertakingMultiFactorAuthentication();

                if (! $user) {
                    return [];
                }

                return [
                    ...Arr::wrap($this->getMultiFactorProviderFormComponent()),
                    ...$this->getMultiFactorChallenge()->getChallengeFormComponents($user),
                    Checkbox::make('trustDevice')
                        ->label(__('Do not ask again on this device for :days days', ['days' => TrustedDevice::DAYS]))
                        ->dehydrated(false),
                ];
            })
            ->statePath('data.multiFactor');
    }

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

    protected function getMultiFactorChallenge(): MultiFactorChallenge
    {
        return TrustedDeviceChallenge::make();
    }

    protected function getBackLink(): ?array
    {
        return $this->isUndertakingMultiFactorAuthentication()
            ? ['url' => filament()->getLoginUrl(), 'label' => __('Back')]
            : null;
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
