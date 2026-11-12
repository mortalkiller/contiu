<?php

namespace App\Filament\Pages\Auth;

use App\Filament\Pages\Auth\Concerns\UsesContiuAuthLayout;
use Filament\Actions\Action;
use Filament\Auth\Pages\PasswordReset\ResetPassword as BaseResetPassword;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rules\Password as PasswordRule;
use SensitiveParameter;

/**
 * "Choose a new password", as in the "Autenticação" mocks: the email goes in the subheading
 * instead of a disabled field, and a strength bar and the rules follow what is typed, in the
 * browser (filament.pages.auth.partials.password-rules).
 */
class ResetPassword extends BaseResetPassword
{
    use UsesContiuAuthLayout;

    protected string $view = 'filament.pages.auth.auth-page';

    protected static string $layout = 'filament.pages.auth.auth-layout';

    public function mount(?string $email = null, #[SensitiveParameter] ?string $token = null): void
    {
        parent::mount($email, $token);

        $this->email ??= $email ?? request()->query('email');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Choose a new password');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('Choose a new password');
    }

    public function getSubheading(): string|Htmlable|null
    {
        if (blank($this->email)) {
            return null;
        }

        return new HtmlString(__('For the account :email. Then sign in with it.', ['email' => '<b>'.e($this->email).'</b>']));
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getPasswordFormComponent()->label(__('New password')),
                $this->getPasswordConfirmationFormComponent()->label(__('Confirm the password')),
                View::make('filament.pages.auth.partials.password-rules')
                    ->viewData(['minimumLength' => PasswordRule::default()->appliedRules()['min']]),
            ]);
    }

    public function getResetPasswordFormAction(): Action
    {
        return parent::getResetPasswordFormAction()->label(__('Save the password'));
    }
}
