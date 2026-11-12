<?php

use App\Filament\Pages\Auth\EmailVerificationPrompt;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Filament\Pages\Auth\ResetPassword;
use App\Models\User;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

pest()->group('tenancy', 'ui');

// The link to the sign-up only shows while it is open (tests/TestCase.php).
pest()->group('registration-open');

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    asAWebRequest();
});

it('draws the sign-in in the split layout, with the recovery and sign-up links', function () {
    $this->get(Filament::getLoginUrl())
        ->assertOk()
        ->assertSee('Entra na Contiu')
        ->assertSee('Esqueceste-te?')
        ->assertSee('Manter a sessão iniciada neste dispositivo')
        ->assertSee('Começa com 14 dias grátis')
        ->assertSee('Faturas, despesas e impostos, sem surpresas no fim do mês.')
        ->assertSee('brand/ilustracoes/contabilista.webp', escape: false);
});

it('asks for the authenticator code before signing in a user with two-step verification', function () {
    $user = User::factory()->create(['email' => 'rita@example.pt', 'password' => 'uma-palavra-passe-longa']);
    $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

    Livewire::test(Login::class)
        ->fillForm(['email' => 'rita@example.pt', 'password' => 'uma-palavra-passe-longa'])
        ->call('authenticate')
        ->assertSee('Código de verificação');

    expect(Filament::auth()->check())->toBeFalse();
});

it('sends the recovery link and then shows where it went', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'rita@example.pt']);

    Livewire::test(RequestPasswordReset::class)
        ->fillForm(['email' => 'rita@example.pt'])
        ->call('request')
        ->assertSet('sentTo', 'rita@example.pt')
        ->assertSee('Vê o teu email')
        ->assertSee('Enviar outra vez');

    Notification::assertSentTo($user, ResetPasswordNotification::class);
});

it('answers an unknown email exactly like a known one, so the form never tells who has an account', function () {
    Notification::fake();

    Livewire::test(RequestPasswordReset::class)
        ->fillForm(['email' => 'ninguem@example.pt'])
        ->call('request')
        ->assertSet('sentTo', 'ninguem@example.pt')
        ->assertSee('Se existir uma conta com')
        ->assertDontSee('Não encontramos');

    Notification::assertNothingSent();
});

it('names the account in the new password page instead of a disabled email field', function () {
    $user = User::factory()->create(['email' => 'rita@example.pt']);

    Livewire::test(ResetPassword::class, ['email' => 'rita@example.pt', 'token' => Password::broker()->createToken($user)])
        ->assertSee('Escolhe uma nova palavra-passe')
        ->assertSee('rita@example.pt')
        ->assertSee('Pelo menos 8 caracteres')
        ->assertFormFieldDoesNotExist('email');
});

it('keeps the new password rules on the server', function () {
    $user = User::factory()->create(['email' => 'rita@example.pt']);

    Livewire::test(ResetPassword::class, ['email' => 'rita@example.pt', 'token' => Password::broker()->createToken($user)])
        ->fillForm(['password' => 'curta', 'passwordConfirmation' => 'curta'])
        ->call('resetPassword')
        ->assertHasFormErrors(['password']);
});

it('asks a user who comes back later to confirm the email, without the “Conta criada” screen', function () {
    $this->actingAs(User::factory()->unverified()->create(['email' => 'rita@example.pt']));

    Livewire::test(EmailVerificationPrompt::class)
        ->assertSee('Confirma o teu email')
        ->assertSee('rita@example.pt')
        ->assertDontSee('O que fica combinado');
});

it('never shows another user’s “Conta criada” screen kept in the session', function () {
    $this->actingAs(User::factory()->unverified()->create());

    session()->put(EmailVerificationPrompt::ACCOUNT_CREATED_SESSION_KEY, ['user' => -1, 'firstName' => 'Outra']);

    Livewire::test(EmailVerificationPrompt::class)
        ->assertSee('Confirma o teu email')
        ->assertDontSee('Outra');
});

it('shows “Email confirmado” after the confirmation link, then goes on to the panel', function () {
    $user = User::factory()->unverified()->create(['name' => 'Rita Silva']);
    $this->account->users()->attach($user, ['role' => 'owner']);
    $this->actingAs($user);

    $link = URL::temporarySignedRoute('filament.admin.auth.email-verification.verify', now()->addHour(), [
        'id' => $user->getKey(),
        'hash' => sha1($user->getEmailForVerification()),
    ]);

    $this->get($link)->assertRedirect(Filament::getEmailVerificationPromptUrl());

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();

    $this->get(Filament::getEmailVerificationPromptUrl())
        ->assertOk()
        ->assertSee('Email confirmado')
        ->assertSee('Obrigado, Rita. O teu email está confirmado.')
        ->assertSee('Entrar na Contiu');
});

it('offers the authenticator app and the email codes in Segurança', function () {
    actingAsAdmin();

    $this->get(url('/profile?section=security'))
        ->assertOk()
        ->assertSee('Verificação em dois passos (2FA)')
        ->assertSee('App de autenticação')
        ->assertSee('Códigos por email');
});
