<?php

namespace App\Filament\Pages\Auth;

use App\Filament\Pages\Auth\Concerns\UsesContiuAuthLayout;
use Filament\Actions\Action;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Locked;

/**
 * "Recover your password", as in the "Autenticação" mocks. Once asked, the page turns into
 * "Check your email" with the same answer whether the account exists or not, so the form never
 * tells who has an account.
 */
class RequestPasswordReset extends BaseRequestPasswordReset
{
    use UsesContiuAuthLayout;

    protected string $view = 'filament.pages.auth.auth-page';

    protected static string $layout = 'filament.pages.auth.auth-layout';

    /** The email the link went to, once asked. */
    #[Locked]
    public ?string $sentTo = null;

    public function getTitle(): string|Htmlable
    {
        return __('Recover your password');
    }

    protected function getEmailFormComponent(): Component
    {
        return parent::getEmailFormComponent()->label(__('Email'));
    }

    public function getHeading(): string|Htmlable|null
    {
        return $this->sentTo === null ? __('Recover your password') : __('Check your email');
    }

    public function getSubheading(): string|Htmlable|null
    {
        if ($this->sentTo === null) {
            return __('Tell us the email of your account. We will send you a link to choose a new one.');
        }

        return new HtmlString(__('If there is an account with :email, we sent a link to choose a new password. The link is valid for :minutes minutes.', [
            'email' => '<b>'.e($this->sentTo).'</b>',
            'minutes' => $this->linkLifetimeInMinutes(),
        ]));
    }

    public function content(Schema $schema): Schema
    {
        $isSent = fn (): bool => $this->sentTo !== null;

        return $schema->components([
            RenderHook::make(PanelsRenderHook::AUTH_PASSWORD_RESET_REQUEST_FORM_BEFORE),
            $this->getFormContentComponent()->hidden($isSent),
            View::make('filament.pages.auth.partials.auth-tips')
                ->viewData(['tips' => [
                    __('Look in spam or promotions.'),
                    __('Make sure it is the email you created the account with.'),
                ]])
                ->visible($isSent),
            Actions::make([$this->resendLinkAction()])->fullWidth()->visible($isSent),
            RenderHook::make(PanelsRenderHook::AUTH_PASSWORD_RESET_REQUEST_FORM_AFTER),
        ]);
    }

    /**
     * "Enviar outra vez", locked with a countdown for as long as Laravel's password broker
     * refuses a new link (its throttle), so the click never ends in "please wait".
     */
    public function resendLinkAction(): Action
    {
        $seconds = $this->resendThrottleInSeconds();

        return Action::make('resendLink')
            ->label(new HtmlString(e(__('Send again')).'<span class="contiu-auth-countdown" x-show="left > 0" x-cloak> · <span x-text="Math.floor(left / 60) + \':\' + String(left % 60).padStart(2, \'0\')"></span></span>'))
            ->color('gray')
            ->size('lg')
            ->extraAttributes([
                'x-data' => "{ left: {$seconds}, tick() { const timer = setInterval(() => { this.left = Math.max(0, this.left - 1); if (this.left === 0) clearInterval(timer) }, 1000) } }",
                'x-init' => 'tick()',
                'x-bind:disabled' => 'left > 0',
                'x-on:click' => "\$nextTick(() => { left = {$seconds}; tick() })",
            ])
            ->action(function (): void {
                $this->data = ['email' => $this->sentTo];

                $this->request();
            });
    }

    protected function getFormActions(): array
    {
        return [$this->getRequestFormAction()->label(__('Send the link'))];
    }

    protected function getBackLink(): ?array
    {
        if ($this->sentTo !== null || ! filament()->hasLogin()) {
            return null;
        }

        return ['url' => filament()->getLoginUrl(), 'label' => __('Back to sign in')];
    }

    protected function getClosingLine(): string|Htmlable|null
    {
        if (! filament()->hasLogin()) {
            return null;
        }

        $link = Blade::render('<x-filament::link :href="$url">{{ $label }}</x-filament::link>', [
            'url' => filament()->getLoginUrl(),
            'label' => $this->sentTo === null ? __('Sign in') : __('Back to sign in'),
        ]);

        return new HtmlString(($this->sentTo === null ? e(__('Remembered it?')).' ' : '').$link);
    }

    protected function getSentNotification(string $status): ?Notification
    {
        $this->sentTo = $this->data['email'] ?? null;

        return null;
    }

    /**
     * An unknown email gets the same "check your email" as a known one. Other failures (a link
     * asked for too soon) keep Filament's notification.
     */
    protected function getFailureNotification(string $status): ?Notification
    {
        if ($status === Password::INVALID_USER) {
            return $this->getSentNotification($status);
        }

        return parent::getFailureNotification($status);
    }

    private function resendThrottleInSeconds(): int
    {
        return (int) config("auth.passwords.{$this->passwordBroker()}.throttle", 60);
    }

    private function passwordBroker(): string
    {
        return Filament::getAuthPasswordBroker() ?? config('auth.defaults.passwords');
    }

    private function linkLifetimeInMinutes(): int
    {
        return (int) config("auth.passwords.{$this->passwordBroker()}.expire", 60);
    }
}
