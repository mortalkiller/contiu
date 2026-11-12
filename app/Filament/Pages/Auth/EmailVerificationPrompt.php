<?php

namespace App\Filament\Pages\Auth;

use App\Filament\Pages\Auth\Concerns\UsesContiuAuthLayout;
use App\Models\User;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Auth\Pages\EmailVerification\EmailVerificationPrompt as BaseEmailVerificationPrompt;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Locked;

/**
 * The email confirmation, in three moments:
 *
 * - right after the sign-up, "Conta criada" (RegConcluido mocks): the tax card, what is agreed,
 *   the first steps and the email to confirm, in the sign-up layout;
 * - "Confirm your email", in the auth layout, when the user comes back later without confirming;
 * - "Email confirmed", after the link (EmailVerificationResponse), which goes on to the panel by
 *   itself after a few seconds.
 */
class EmailVerificationPrompt extends BaseEmailVerificationPrompt
{
    use UsesContiuAuthLayout;

    /** What RegisterAccount leaves for the "Conta criada" screen, for this session. */
    public const ACCOUNT_CREATED_SESSION_KEY = 'contiu.account_created';

    /** Flashed by EmailVerificationResponse once the link is opened. */
    public const CONFIRMED_SESSION_KEY = 'contiu.email_confirmed';

    protected string $view = 'filament.pages.auth.auth-page';

    protected static string $layout = 'filament.pages.auth.auth-layout';

    #[Locked]
    public bool $isConfirmed = false;

    /** Where "Email confirmed" goes next. */
    #[Locked]
    public ?string $nextUrl = null;

    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $accountCreated = null;

    public function mount(): void
    {
        if (Filament::auth()->check() && session()->get(self::CONFIRMED_SESSION_KEY)) {
            $this->isConfirmed = true;
            $this->nextUrl = session()->pull('url.intended', Filament::getUrl());
            $this->accountCreated = session()->pull(self::ACCOUNT_CREATED_SESSION_KEY);

            return;
        }

        parent::mount();

        $accountCreated = session()->get(self::ACCOUNT_CREATED_SESSION_KEY);

        if (is_array($accountCreated) && ($accountCreated['user'] ?? null) === Filament::auth()->id()) {
            $this->accountCreated = $accountCreated;
        }
    }

    public function getView(): string
    {
        return $this->showsAccountCreated() ? 'filament.pages.auth.account-created' : parent::getView();
    }

    public function getLayout(): string
    {
        return $this->showsAccountCreated() ? 'filament.pages.auth.signup-layout' : parent::getLayout();
    }

    public function getTitle(): string|Htmlable
    {
        return match (true) {
            $this->isConfirmed => __('Email confirmed'),
            $this->showsAccountCreated() => __('Account created'),
            default => __('Confirm your email'),
        };
    }

    public function getHeading(): string|Htmlable|null
    {
        return $this->getTitle();
    }

    public function getSubheading(): string|Htmlable|null
    {
        if ($this->isConfirmed) {
            $name = (string) strtok((string) Filament::auth()->user()?->name, ' ');

            return $this->accountCreated !== null
                ? __('Thank you, :name. Your account is active and your :days free days are already counting.', ['name' => $name, 'days' => config('contiu.trial_days')])
                : __('Thank you, :name. Your email is confirmed.', ['name' => $name]);
        }

        return new HtmlString(__('We sent a link to :email. Open it on this device or another to activate your account.', [
            'email' => '<b>'.e($this->getVerifiable()->getEmailForVerification()).'</b>',
        ]));
    }

    public function content(Schema $schema): Schema
    {
        if ($this->isConfirmed) {
            return $schema->components([
                View::make('filament.pages.auth.partials.auth-confirmed')
                    ->viewData(['nextUrl' => $this->nextUrl]),
            ]);
        }

        return $schema->components([
            View::make('filament.pages.auth.partials.auth-mailbox'),
            Actions::make([$this->resendNotificationAction])->fullWidth(),
            View::make('filament.pages.auth.partials.auth-tips')
                ->viewData(['tips' => [
                    __('Look in spam or promotions.'),
                    __('Still nothing after a few minutes? Send it again.'),
                    new HtmlString(e(__('Typed the email wrong?')).' '.$this->changeEmailAction->toHtml()),
                ]]),
        ]);
    }

    public function resendNotificationAction(): Action
    {
        return parent::resendNotificationAction()
            ->button()
            ->color('gray')
            ->size($this->showsAccountCreated() ? 'sm' : 'lg')
            ->label(__('Send again'));
    }

    /**
     * "Usar outro email": a typo in the sign-up email would otherwise leave the account locked.
     * The new address must be free; the link goes to it, and the old link stops working (it is
     * signed over the email).
     */
    public function changeEmailAction(): Action
    {
        return Action::make('changeEmail')
            ->link()
            ->label(__('Use another email'))
            ->modalHeading(__('Use another email'))
            ->modalDescription(__('We send the confirmation link to the new address.'))
            ->modalSubmitActionLabel(__('Send the link'))
            ->modalWidth('md')
            ->schema([
                TextInput::make('email')
                    ->label(__('Email'))
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(table: 'users', column: 'email', ignorable: fn (): ?Model => Filament::auth()->user()),
            ])
            ->action(function (array $data): void {
                try {
                    $this->rateLimit(3);
                } catch (TooManyRequestsException $exception) {
                    $this->getRateLimitedNotification($exception)?->send();

                    return;
                }

                /** @var User $user */
                $user = Filament::auth()->user();
                $user->forceFill(['email' => $data['email'], 'email_verified_at' => null])->save();

                if ($this->accountCreated !== null) {
                    $this->accountCreated['email'] = $user->email;
                    session()->put(self::ACCOUNT_CREATED_SESSION_KEY, $this->accountCreated);
                }

                $this->sendEmailVerificationNotification($user);

                Notification::make()
                    ->title(__('We sent a new link to :email', ['email' => $user->email]))
                    ->success()
                    ->send();
            });
    }

    protected function getClosingLine(): string|Htmlable|null
    {
        if ($this->isConfirmed) {
            return null;
        }

        return new HtmlString(Blade::render(<<<'BLADE'
            <form method="post" action="{{ filament()->getLogoutUrl() }}" class="contiu-auth-inline-form">
                @csrf
                <x-filament::link tag="button" type="submit">{{ __('Sign out') }}</x-filament::link>
            </form>
            BLADE));
    }

    private function showsAccountCreated(): bool
    {
        return ! $this->isConfirmed && $this->accountCreated !== null;
    }
}
