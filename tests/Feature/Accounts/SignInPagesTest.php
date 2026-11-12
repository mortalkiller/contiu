<?php

use App\Filament\Pages\Auth\EmailVerificationPrompt;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Filament\Pages\Auth\ResetPassword;
use App\Models\User;
use App\Support\TrustedDevice;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Auth\Notifications\VerifyEmail;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use PragmaRX\Google2FAQRCode\Google2FA;

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

it('trusts the device for 30 days once the code is right and “do not ask again” is ticked', function () {
    $user = User::factory()->create(['email' => 'rita@example.pt', 'password' => 'uma-palavra-passe-longa']);
    $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

    Livewire::test(Login::class)
        ->fillForm(['email' => 'rita@example.pt', 'password' => 'uma-palavra-passe-longa'])
        ->call('authenticate')
        ->assertSee('Não pedir de novo neste dispositivo durante 30 dias')
        ->set('data.multiFactor.app.code', app(Google2FA::class)->getCurrentOtp('JBSWY3DPEHPK3PXP'))
        ->set('data.multiFactor.trustDevice', true)
        ->call('authenticate')
        ->assertHasNoErrors();

    expect(Filament::auth()->id())->toBe($user->id)
        ->and(Cookie::hasQueued(app(TrustedDevice::class)->cookieName($user)))->toBeTrue();
});

it('signs in without the code on a trusted device, until the password changes', function () {
    $user = User::factory()->create(['email' => 'rita@example.pt', 'password' => 'uma-palavra-passe-longa']);
    $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

    app(TrustedDevice::class)->trust($user);
    $cookie = Cookie::queued(app(TrustedDevice::class)->cookieName($user));

    Livewire::withCookie($cookie->getName(), $cookie->getValue())
        ->test(Login::class)
        ->fillForm(['email' => 'rita@example.pt', 'password' => 'uma-palavra-passe-longa'])
        ->call('authenticate')
        ->assertDontSee('Código de verificação');

    expect(Filament::auth()->id())->toBe($user->id);

    Filament::auth()->logout();
    $user->forceFill(['password' => 'outra-palavra-passe-longa'])->save();

    Livewire::withCookie($cookie->getName(), $cookie->getValue())
        ->test(Login::class)
        ->fillForm(['email' => 'rita@example.pt', 'password' => 'outra-palavra-passe-longa'])
        ->call('authenticate')
        ->assertSee('Código de verificação');

    expect(Filament::auth()->check())->toBeFalse();
});

it('does not trust a device with a forged cookie', function () {
    $user = User::factory()->create(['email' => 'rita@example.pt', 'password' => 'uma-palavra-passe-longa']);
    $user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

    Livewire::withCookie(app(TrustedDevice::class)->cookieName($user), now()->addDay()->getTimestamp().'.'.str_repeat('a', 64))
        ->test(Login::class)
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

it('sends the confirmation link to another email when the first one was mistyped', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create(['email' => 'rita@exmaple.pt']);
    $this->actingAs($user);

    Livewire::test(EmailVerificationPrompt::class)
        ->callAction('changeEmail', ['email' => 'rita@example.pt'])
        ->assertHasNoActionErrors()
        ->assertSee('rita@example.pt');

    expect($user->fresh()->email)->toBe('rita@example.pt')
        ->and($user->fresh()->hasVerifiedEmail())->toBeFalse();

    Notification::assertSentTo($user, VerifyEmail::class);
});

it('refuses another email that already has an account', function () {
    User::factory()->create(['email' => 'ocupado@example.pt']);
    $user = User::factory()->unverified()->create(['email' => 'rita@exmaple.pt']);
    $this->actingAs($user);

    Livewire::test(EmailVerificationPrompt::class)
        ->callAction('changeEmail', ['email' => 'ocupado@example.pt'])
        ->assertHasActionErrors(['email' => 'unique']);

    expect($user->fresh()->email)->toBe('rita@exmaple.pt');
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

it('switches the sign-in pages to English from the footer, and back to where it was picked', function () {
    $this->from(Filament::getLoginUrl())
        ->get(route('guest-language', 'en-US'))
        ->assertRedirect(Filament::getLoginUrl());

    $this->get(Filament::getLoginUrl())
        ->assertSee('Sign in to Contiu')
        ->assertSee('English (United States)')
        ->assertDontSee('Entra na Contiu');
});

it('never sends the language switch back to another site', function () {
    $this->from('https://example.com/phishing')
        ->get(route('guest-language', 'en-US'))
        ->assertRedirect(Filament::getLoginUrl());
});

it('refuses a language Contiu does not have', function () {
    $this->get('/idioma/xx-XX')->assertNotFound();
});
