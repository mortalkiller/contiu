<?php

use App\Actions\CreateAccount;
use App\Filament\Pages\Auth\RegisterAccount;
use App\Filament\Pages\Dashboard;
use App\Models\Account;
use App\Models\Category;
use App\Models\User;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;
use Database\Seeders\DatabaseSeeder;
use Filament\Auth\Notifications\VerifyEmail;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

// Public sign-up is off unless CONTIU_REGISTRATION_OPEN is set (tests/TestCase.php).
pest()->group('registration-open');

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-08 10:00'));
    Filament::setCurrentPanel('admin');
    // A visitor signing up: no one logged in and no account yet.
    asAWebRequest();
    Notification::fake();
});

/** Everything the wizard asks, as the visitor types it (money in euros, rates in %). */
function registrationData(array $overrides = []): array
{
    return [
        'name' => 'Rita Silva',
        'email' => 'rita@example.pt',
        'password' => 'uma-palavra-passe-longa',
        'passwordConfirmation' => 'uma-palavra-passe-longa',
        'account_name' => 'Estúdio Rita',
        'nif' => '501964843',
        'activity_started_on' => '2026-03-01',
        'vat_exempt' => true,
        'vat_exemption_threshold' => 15000,
        'ss_rate_percent' => 21.4,
        'ss_relevant_income_share_percent' => 70,
        'has_business_account' => true,
        'account_names' => " RITA SILVA \n\nOrdenado",
        'terms' => true,
        ...$overrides,
    ];
}

function registrationWizard(array $overrides = [])
{
    return Livewire::test(RegisterAccount::class)->fillForm(registrationData($overrides));
}

it('creates user and account from the wizard', function () {
    registrationWizard()
        ->call('register')
        ->assertHasNoFormErrors()
        ->assertRedirect(url('/estudio-rita'));

    $user = User::firstWhere('email', 'rita@example.pt');
    $account = Account::firstWhere('nif', '501964843');

    expect($user->name)->toBe('Rita Silva')
        ->and(Hash::check('uma-palavra-passe-longa', $user->password))->toBeTrue()
        ->and($user->hasVerifiedEmail())->toBeFalse()
        ->and($user->terms_version)->toBe('2026-11-08')
        ->and($user->terms_accepted_at->toDateTimeString())->toBe('2026-11-08 10:00:00')
        ->and($account->name)->toBe('Estúdio Rita')
        ->and($account->slug)->toBe('estudio-rita')
        ->and($account->owner()?->is($user))->toBeTrue()
        ->and($account->trial_ends_at->toDateString())->toBe('2026-11-22');

    CurrentAccount::run($account, function () {
        $settings = app(FinanceSettings::class);

        expect($settings->activityStartedOn()?->toDateString())->toBe('2026-03-01')
            ->and($settings->vatExempt())->toBeTrue()
            ->and($settings->vatExemptionThreshold())->toBe(1500000)
            ->and($settings->ssRate())->toBe(0.214)
            ->and($settings->ssRelevantIncomeShare())->toBe(0.70)
            ->and($settings->hasBusinessAccount())->toBeTrue()
            ->and($settings->accountNames())->toBe(['RITA SILVA', 'Ordenado']);
    });

    $this->assertAuthenticatedAs($user);
    Notification::assertSentTo($user, VerifyEmail::class);

    // The account opens only after the email is confirmed: the dashboard sends to the prompt.
    $this->get(Dashboard::getUrl(tenant: $account))
        ->assertRedirect(Filament::getEmailVerificationPromptUrl());
});

it('leaves the bank out for a freelancer without an account only for the activity', function () {
    registrationWizard(['has_business_account' => false])
        ->call('register')
        ->assertHasNoFormErrors();

    CurrentAccount::run(Account::firstWhere('nif', '501964843'), function () {
        $settings = app(FinanceSettings::class);

        expect($settings->hasBusinessAccount())->toBeFalse()
            ->and($settings->accountNames())->toBe([]);
    });
});

it('starts with the usual rates and VAT limit', function () {
    Livewire::test(RegisterAccount::class)->assertFormSet([
        'ss_rate_percent' => 21.4,
        'ss_relevant_income_share_percent' => 70,
        'vat_exemption_threshold' => 15000,
        'terms' => false,
    ]);
});

it('no longer asks for the first-year exemption, now computed from the activity', function () {
    Livewire::test(RegisterAccount::class)
        ->assertFormFieldDoesNotExist('ss_first_year_exempt')
        ->assertDontSee('Isento nos primeiros 12 meses');
});

it('no longer asks for an IRS exemption, now computed from the IRS settings', function () {
    Livewire::test(RegisterAccount::class)
        ->assertFormFieldDoesNotExist('irs_exempt')
        ->assertDontSee('Isento de IRS');
});

it('stores no VAT limit when the account is not VAT exempt', function () {
    registrationWizard(['vat_exempt' => false, 'vat_exemption_threshold' => 999])
        ->call('register')
        ->assertHasNoFormErrors();

    CurrentAccount::run(Account::firstWhere('nif', '501964843'), function () {
        $settings = app(FinanceSettings::class);

        expect($settings->vatExempt())->toBeFalse()
            ->and($settings->paymentsExemptByDefault())->toBeFalse()
            ->and($settings->vatExemptionThreshold())->toBe(1500000);
    });
});

it('rejects an invalid or repeated NIF', function (string $nif, string $message) {
    Account::factory()->create(['nif' => '123456789']);

    registrationWizard(['nif' => $nif])
        ->goToWizardStep(3)
        ->assertHasFormErrors(['nif' => $message])
        ->assertWizardCurrentStep(2);

    expect(Account::where('nif', $nif)->count())->toBeLessThanOrEqual(1);
})->with([
    'wrong check digit' => ['123456780', 'O NIF não é válido.'],
    'trailing newline' => ["501964843\n", 'O NIF não é válido.'],
    'already has an account' => ['123456789', 'Já existe uma conta com este NIF.'],
]);

it('does not take an email that already has a user', function () {
    User::factory()->create(['email' => 'rita@example.pt']);

    registrationWizard()
        ->goToWizardStep(2)
        ->assertHasFormErrors(['email' => 'unique'])
        ->assertWizardCurrentStep(1);
});

it('refuses a negative VAT limit', function () {
    registrationWizard(['vat_exemption_threshold' => -1])
        ->call('register')
        ->assertHasFormErrors(['vat_exemption_threshold' => 'min']);

    expect(Account::where('nif', '501964843')->exists())->toBeFalse();
});

it('requires accepting the terms', function () {
    registrationWizard(['terms' => false])
        ->call('register')
        ->assertHasFormErrors(['terms' => 'accepted']);

    expect(User::where('email', 'rita@example.pt')->exists())->toBeFalse()
        ->and(Account::where('nif', '501964843')->exists())->toBeFalse();
});

it('leaves no user behind when the account cannot be created', function () {
    $this->mock(CreateAccount::class)->shouldReceive('handle')->andThrow(new RuntimeException('falhou'));

    expect(fn () => registrationWizard()->call('register'))->toThrow(RuntimeException::class, 'falhou');

    expect(User::where('email', 'rita@example.pt')->exists())->toBeFalse();
    $this->assertGuest();
});

it('blocks the panel until the email is verified', function () {
    $user = User::factory()->unverified()->create();
    $this->account->users()->attach($user, ['role' => 'owner']);
    $this->actingAs($user);

    $this->get(Dashboard::getUrl(tenant: $this->account))
        ->assertRedirect(Filament::getEmailVerificationPromptUrl());
    $this->get(Filament::getEmailVerificationPromptUrl())->assertOk();

    // The link in the email confirms it and opens the account.
    $this->get(Filament::getVerifyEmailUrl($user))
        ->assertRedirect(Dashboard::getUrl(tenant: $this->account));

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    $this->get(Dashboard::getUrl(tenant: $this->account))->assertOk();
});

it('keeps letting verified users in', function () {
    $this->actingAs(ownerOf($this->account));

    $this->get(Dashboard::getUrl(tenant: $this->account))->assertOk();
});

it('seeds a local admin whose email is already verified', function () {
    $this->seed(DatabaseSeeder::class);

    expect(User::firstWhere('email', 'admin@pressiumize.test')->hasVerifiedEmail())->toBeTrue();
});

it('seeds an example account owned by the local admin, without billing', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class); // safe to run again

    $account = Account::where('name', 'Conta de exemplo')->sole();
    $admin = User::firstWhere('email', 'admin@pressiumize.test');

    expect($account)
        ->billing_exempt->toBeTrue()
        ->hasAccess()->toBeTrue()
        ->and($account->owner()?->is($admin))->toBeTrue()
        ->and($admin->isSuperAdmin())->toBeTrue()
        ->and(CurrentAccount::run($account, fn () => Category::pluck('name')))
        ->toContain('Serviços', 'Software e subscrições', 'Comissões')
        ->toHaveCount(11);
});

it('shows the email confirmation prompt and email in European Portuguese', function () {
    $user = User::factory()->unverified()->create(['email' => 'rita@example.pt']);
    $this->account->users()->attach($user, ['role' => 'owner']);
    $this->actingAs($user);

    $this->get(Filament::getEmailVerificationPromptUrl())
        ->assertOk()
        ->assertSee('Confirma o teu email')
        ->assertSee('Não recebeste o email?')
        ->assertDontSee('Verifique');

    $notification = new VerifyEmail;
    $notification->url = 'https://contiu.test/confirmar';
    $mail = $notification->toMail($user);
    $html = (string) $mail->render();

    expect($mail->subject)->toBe('Confirma o teu email')
        ->and($mail->actionText)->toBe('Confirmar email')
        ->and($html)->toContain('Olá!')
        ->toContain('Se não criaste uma conta, ignora este email.')
        ->not->toContain('Regards')
        ->not->toContain('trouble clicking')
        ->not->toContain('All rights reserved');
});

it('shows the sign-up page with links to the terms and the privacy policy', function () {
    $this->get(Filament::getRegistrationUrl())
        ->assertOk()
        ->assertSee('Criar conta')
        ->assertSee(url('/termos'), escape: false)
        ->assertSee(url('/privacidade'), escape: false);

    $this->get(Filament::getLoginUrl())->assertSee(Filament::getRegistrationUrl(), escape: false);
});

it('serves the terms and privacy pages', function (string $path, string $title) {
    $this->get($path)
        ->assertOk()
        ->assertSee($title)
        ->assertSee('Texto a fornecer pelo Pedro')
        ->assertSee('Versão 2026-11-08')
        // Placeholder text until Pedro's: kept out of search engines.
        ->assertSee('<meta name="robots" content="noindex">', escape: false)
        ->assertSee('brand/contiu/contiu-logo-white.svg', escape: false);
})->with([
    'terms' => ['/termos', 'Termos de Serviço'],
    'privacy' => ['/privacidade', 'Política de Privacidade'],
]);

it('keeps the sign-up page logo while moving between steps', function () {
    // Each step is a Livewire update: the route is Livewire's, not the sign-up page's.
    registrationWizard()
        ->goToWizardStep(2)
        ->assertWizardCurrentStep(2)
        ->assertSee('brand/contiu/contiu-logo-black.svg', escape: false)
        ->assertSee('height: 2.5rem', escape: false);
});
