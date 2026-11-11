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

pest()->group('tenancy');

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
        'activity_start_kind' => 'restart',
        'previous_activity_ended_on' => '2019-06-30',
        'ss_enrolled_from' => '2026-03-01',
        'dependent_income_current_year' => true,
        'irs_coefficient' => '0.35',
        'irs_jovem' => true,
        'birth_date' => '1997-04-12',
        'irs_jovem_year_number' => 2,
        'vat_exempt' => true,
        'vat_exemption_threshold' => 15000,
        'ss_rate' => '0.214',
        'ss_exemption_reason' => 'accumulation',
        'ss_contribution_exempt' => true,
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
            ->and($settings->activityStartKind())->toBe('restart')
            ->and($settings->previousActivityEndedOn()?->toDateString())->toBe('2019-06-30')
            ->and($settings->ssEnrolledFrom()?->toDateString())->toBe('2026-03-01')
            ->and($settings->dependentIncomeYears())->toBe([2026])
            ->and($settings->irsCoefficient())->toBe(0.35)
            ->and($settings->irsJovem())->toBeTrue()
            ->and($settings->birthDate()?->toDateString())->toBe('1997-04-12')
            ->and($settings->irsJovemYearNumber(2026))->toBe(2)
            ->and($settings->ssContributionExempt())->toBeTrue()
            ->and($settings->reverseChargeVatRate())->toBe(0.23)
            ->and($settings->isIncomplete())->toBeFalse()
            ->and($settings->hasBusinessAccount())->toBeTrue()
            ->and($settings->accountNames())->toBe(['RITA SILVA', 'Ordenado']);
    });

    $this->assertAuthenticatedAs($user);
    Notification::assertSentTo($user, VerifyEmail::class);

    // The account opens only after the email is confirmed: the dashboard sends to the prompt.
    $this->get(Dashboard::getUrl(tenant: $account))
        ->assertRedirect(Filament::getEmailVerificationPromptUrl());
})->group('ui');

it('leaves the bank out for a freelancer without an account only for the activity', function () {
    registrationWizard(['has_business_account' => false])
        ->call('register')
        ->assertHasNoFormErrors();

    CurrentAccount::run(Account::firstWhere('nif', '501964843'), function () {
        $settings = app(FinanceSettings::class);

        expect($settings->hasBusinessAccount())->toBeFalse()
            ->and($settings->accountNames())->toBe([]);
    });
})->group('integration');

it('starts with the usual rates and VAT limit', function () {
    Livewire::test(RegisterAccount::class)->assertFormSet([
        'activity_start_kind' => 'first',
        'irs_coefficient' => '0.75',
        'irs_jovem' => false,
        'dependent_income_current_year' => false,
        'ss_rate' => '0.214',
        'ss_contribution_exempt' => false,
        'ss_exemption_reason' => null,
        'vat_exemption_threshold' => 15000,
        'terms' => false,
    ]);
})->group('ui');

it('no longer asks for the first-year exemption, now computed from the activity', function () {
    Livewire::test(RegisterAccount::class)
        ->assertFormFieldDoesNotExist('ss_first_year_exempt')
        ->assertDontSee('Isento nos primeiros 12 meses');
})->group('ui');

it('no longer asks for an IRS exemption, now computed from the IRS settings', function () {
    Livewire::test(RegisterAccount::class)
        ->assertFormFieldDoesNotExist('irs_exempt')
        ->assertDontSee('Isento de IRS');
})->group('ui');

it('stores a first start without the restart, IRS Jovem or salary answers', function () {
    registrationWizard([
        'activity_start_kind' => 'first',
        'dependent_income_current_year' => false,
        'irs_jovem' => false,
        'ss_enrolled_from' => null,
    ])
        ->call('register')
        ->assertHasNoFormErrors();

    CurrentAccount::run(Account::firstWhere('nif', '501964843'), function () {
        $settings = app(FinanceSettings::class);

        expect($settings->activityStartKind())->toBe('first')
            ->and($settings->previousActivityEndedOn())->toBeNull()
            ->and($settings->ssEnrolledFrom())->toBeNull()
            ->and($settings->dependentIncomeYears())->toBe([])
            ->and($settings->irsJovem())->toBeFalse()
            ->and($settings->birthDate())->toBeNull()
            ->and($settings->irsJovemYearNumber(2026))->toBeNull();
    });
})->group('integration');

it('asks for the birth date and the year number with IRS Jovem', function () {
    registrationWizard(['birth_date' => null, 'irs_jovem_year_number' => null])
        ->call('register')
        ->assertHasFormErrors(['birth_date' => 'required', 'irs_jovem_year_number' => 'required']);

    expect(Account::where('nif', '501964843')->exists())->toBeFalse();
})->group('integration');

it('takes a restart without the cessation date, which few freelancers can find', function () {
    registrationWizard(['previous_activity_ended_on' => null])
        ->call('register')
        ->assertHasNoFormErrors();

    CurrentAccount::run(Account::firstWhere('nif', '501964843'), function () {
        expect(app(FinanceSettings::class)->previousActivityEndedOn())->toBeNull();
    });
})->group('integration');

it('says where to find each fiscal answer', function () {
    registrationWizard()
        ->assertSee('Onde ver?')
        ->assertSee('Declarações de Atividade')
        ->assertSee('Enquadramento em IVA')
        ->assertSee('Forma de atividade (taxa)');
})->group('integration');

it('sums up the tax answers before creating the account', function () {
    registrationWizard()
        ->goToWizardStep(7)
        ->assertSee('Reinício a 01/03/2026 (cessação anterior a 30/06/2019)')
        ->assertSee('Enquadrado na Segurança Social a partir de 01/03/2026')
        ->assertSee('Com salário ou pensão em 2026')
        ->assertSee('Coeficiente 0,35 · IRS Jovem, ano n.º 2 em 2026')
        ->assertSee('21,4 % sobre 70 % do rendimento · isento de contribuições (art. 157.º)');
})->group('integration');

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
})->group('integration');

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
])->group('integration');

it('does not take an email that already has a user', function () {
    User::factory()->create(['email' => 'rita@example.pt']);

    registrationWizard()
        ->goToWizardStep(2)
        ->assertHasFormErrors(['email' => 'unique'])
        ->assertWizardCurrentStep(1);
})->group('integration');

it('refuses a negative VAT limit', function () {
    registrationWizard(['vat_exemption_threshold' => -1])
        ->call('register')
        ->assertHasFormErrors(['vat_exemption_threshold' => 'min']);

    expect(Account::where('nif', '501964843')->exists())->toBeFalse();
})->group('integration');

it('requires accepting the terms', function () {
    registrationWizard(['terms' => false])
        ->call('register')
        ->assertHasFormErrors(['terms' => 'accepted']);

    expect(User::where('email', 'rita@example.pt')->exists())->toBeFalse()
        ->and(Account::where('nif', '501964843')->exists())->toBeFalse();
})->group('integration');

it('leaves no user behind when the account cannot be created', function () {
    $this->mock(CreateAccount::class)->shouldReceive('handle')->andThrow(new RuntimeException('falhou'));

    expect(fn () => registrationWizard()->call('register'))->toThrow(RuntimeException::class, 'falhou');

    expect(User::where('email', 'rita@example.pt')->exists())->toBeFalse();
    $this->assertGuest();
})->group('integration');

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
})->group('ui');

it('keeps letting verified users in', function () {
    $this->actingAs(ownerOf($this->account));

    $this->get(Dashboard::getUrl(tenant: $this->account))->assertOk();
})->group('ui');

it('seeds a local admin whose email is already verified', function () {
    $this->seed(DatabaseSeeder::class);

    expect(User::firstWhere('email', 'admin@pressiumize.test')->hasVerifiedEmail())->toBeTrue();
})->group('integration');

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
})->group('integration');

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
})->group('ui');

it('shows the sign-up page with links to the terms and the privacy policy', function () {
    $this->get(Filament::getRegistrationUrl())
        ->assertOk()
        ->assertSee('Criar conta')
        ->assertSee(url('/termos'), escape: false)
        ->assertSee(url('/privacidade'), escape: false);

    $this->get(Filament::getLoginUrl())->assertSee(Filament::getRegistrationUrl(), escape: false);
})->group('ui');

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
])->group('ui');

it('keeps the sign-up page logo while moving between steps', function () {
    // Each step is a Livewire update: the route is Livewire's, not the sign-up page's.
    registrationWizard()
        ->goToWizardStep(2)
        ->assertWizardCurrentStep(2)
        ->assertSee('brand/contiu/contiu-logo-black.svg', escape: false)
        ->assertSee('height: 2.5rem', escape: false);
})->group('integration');
