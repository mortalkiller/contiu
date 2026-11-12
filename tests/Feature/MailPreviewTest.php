<?php

use App\Support\MailPreviews\MailPreviewRegistry;
use Filament\Auth\Notifications\ResetPassword;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/*
 * The local email preview (/dev/mail-preview): every email the app sends, with example data, and a
 * test send. The routes only exist on a local machine and in the tests.
 */

pest()->group('integration');

it('lists every email and renders each in both languages', function () {
    $this->get(route('dev.mail-preview.index'))
        ->assertOk()
        ->assertSee('Contiu · emails')
        ->assertSee('Free trial ending');

    foreach (array_keys(app(MailPreviewRegistry::class)->templateOptions()) as $template) {
        foreach (['pt_PT', 'en_US'] as $locale) {
            $this->get(route('dev.mail-preview.render', $template).'?locale='.$locale)->assertOk();
        }
    }

    $this->get(route('dev.mail-preview.render', 'trial-ending-soon').'?locale=pt_PT')
        ->assertSee('IVA incluído')
        ->assertSee('66,42');
});

it('gives the subject of the chosen email and language', function () {
    $this->getJson(route('dev.mail-preview.metadata', ['template' => 'trial-ending-soon', 'locale' => 'en_US']))
        ->assertOk()
        ->assertJsonPath('subject', fn (string $subject): bool => str_starts_with($subject, 'Your free trial ends on'));
});

it('sends the chosen email to the address given', function () {
    Mail::fake();
    Notification::fake();

    $this->postJson(route('dev.mail-preview.send'), ['template' => 'tax-obligations-digest', 'locale' => 'pt_PT', 'email' => 'eu@exemplo.pt'])->assertOk();
    $this->postJson(route('dev.mail-preview.send'), ['template' => 'password-reset', 'locale' => 'pt_PT', 'email' => 'eu@exemplo.pt'])->assertOk();

    Mail::assertSent(Mailable::class, fn (Mailable $mail): bool => $mail->hasTo('eu@exemplo.pt'));
    Notification::assertSentTo(new AnonymousNotifiable, ResetPassword::class, fn ($notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'eu@exemplo.pt');
});

it('refuses an unknown email or address', function () {
    $this->postJson(route('dev.mail-preview.send'), ['template' => 'nope', 'locale' => 'pt_PT', 'email' => 'not-an-email'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['template', 'email']);
});
