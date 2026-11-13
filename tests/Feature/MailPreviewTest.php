<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\MailPreviewPage;
use App\Mail\FiscalDocumentEmail;
use App\Models\Client;
use App\Support\MailPreviews\MailPreviewRegistry;
use Filament\Auth\Notifications\ResetPassword;
use Filament\Facades\Filament;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

pest()->group('integration');

it('opens the email previews in Tools independently of the invoice benchmark', function () {
    config(['invoice-benchmark.enabled' => false]);
    $this->withoutVite();
    actingAsAdmin();
    $toolsHome = route('filament.tools.home', ['tenant' => $this->account]);
    $preview = MailPreviewPage::getUrl(panel: 'tools', tenant: $this->account);

    $this->get(Dashboard::getUrl(panel: 'admin', tenant: $this->account))
        ->assertOk()
        ->assertSee($toolsHome, escape: false);
    $this->get($toolsHome)->assertRedirect($preview);
    $this->get($preview)
        ->assertOk()
        ->assertSee('Pré-visualização de emails')
        ->assertSee('Fim do período experimental')
        ->assertSee('fi-panel-tools', escape: false);
});

it('renders each email in both languages using synthetic data', function () {
    actingAsAdmin();
    Client::factory()->create(['name' => 'Private customer']);

    foreach (array_keys(app(MailPreviewRegistry::class)->templateOptions()) as $template) {
        foreach (['pt_PT', 'en_US'] as $locale) {
            $this->get(route('filament.tools.mail-preview.render', ['tenant' => $this->account, 'template' => $template, 'locale' => $locale]))
                ->assertOk()
                ->assertDontSee('Private customer');
        }
    }

    $this->get(route('filament.tools.mail-preview.render', ['tenant' => $this->account, 'template' => 'trial-ending-soon', 'locale' => 'pt_PT']))
        ->assertSee('IVA incluído')
        ->assertSee('75,60');
});

it('shows the subject and preview link for the selected email language', function () {
    actingAsAdmin();
    Filament::setCurrentPanel(Filament::getPanel('tools'));

    Livewire::test(MailPreviewPage::class)
        ->set('locale', 'en_US')
        ->assertSee('Your free trial ends on')
        ->assertSee('locale=en_US', escape: false)
        ->assertActionShouldOpenUrlInNewTab('openPreview');
});

it('sends the chosen email to the address given', function () {
    actingAsAdmin();
    Filament::setCurrentPanel(Filament::getPanel('tools'));
    Mail::fake();
    Notification::fake();

    $page = Livewire::test(MailPreviewPage::class);
    foreach (['tax-obligations-digest', 'password-reset', 'fiscal-document'] as $template) {
        $page->set('template', $template)
            ->callAction('sendPreview', data: ['email' => 'eu@example.test'])
            ->assertHasNoActionErrors();
    }

    Mail::assertSent(Mailable::class, fn (Mailable $mail): bool => $mail->hasTo('eu@example.test'));
    Mail::sent(FiscalDocumentEmail::class)->sole()
        ->assertTo('eu@example.test')
        ->assertHasAttachment(resource_path('fixtures/invoice-email-sample.pdf'), ['as' => 'FT-EMAIL-1.pdf', 'mime' => 'application/pdf']);
    Notification::assertSentTo(new AnonymousNotifiable, ResetPassword::class, fn ($notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'eu@example.test');
});

it('refuses an invalid recipient', function () {
    actingAsAdmin();
    Filament::setCurrentPanel(Filament::getPanel('tools'));
    Mail::fake();

    Livewire::test(MailPreviewPage::class)
        ->callAction('sendPreview', data: ['email' => 'not-an-email'])
        ->assertHasActionErrors(['email']);

    Mail::assertNothingSent();
});

it('requires a super admin on both the page and rendered email and retires the dev URL', function () {
    $preview = MailPreviewPage::getUrl(panel: 'tools', tenant: $this->account);
    $render = route('filament.tools.mail-preview.render', ['tenant' => $this->account, 'template' => 'trial-ending-soon']);
    asAWebRequest();

    $this->get($preview)->assertRedirect(route('filament.tools.auth.login'));
    $this->get($render)->assertRedirect(route('filament.tools.auth.login'));

    $this->actingAs(ownerOf($this->account))->get($preview)->assertForbidden();
    $this->get($render)->assertForbidden();
    $this->get('/dev/mail-preview')->assertNotFound();
    $this->postJson('/dev/mail-preview/send')->assertNotFound();

    $this->actingAs(superAdminOf($this->account))
        ->get(route('filament.tools.mail-preview.render', ['tenant' => $this->account, 'template' => 'unknown']))
        ->assertNotFound();
});
