<?php

use App\Actions\CreateAccount;
use App\Actions\StartSignupSubscription;
use App\Filament\Pages\Auth\RegisterAccount;
use App\Filament\Pages\Dashboard;
use App\Models\Account;
use App\Models\Category;
use App\Models\FiscalProfile;
use App\Models\User;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;
use App\Support\Money;
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
    // The beta takes no payments: the plan and card step is off unless a test turns it on.
    config(['contiu.signup_payment' => false]);
});

/**
 * Every answer of the path with the activity open (simplified regime), as the visitor gives
 * them (the turnover in euros): a programmer with a job elsewhere and IRS Jovem.
 */
function registrationData(array $overrides = []): array
{
    return [
        'name' => 'Rita Silva',
        'email' => 'rita@example.pt',
        'password' => 'uma-palavra-passe-longa',
        'activity_opened' => 'yes',
        'activity_started_on' => '2026-03-01',
        'activity_start_kind' => 'first',
        'accounting_regime' => 'simplified',
        'nif' => '501964843',
        'situation' => ['employment'],
        'employer_unrelated' => 'yes',
        'salary_at_least_ias' => 'yes',
        'employment_scheme' => 'ss',
        'birth_date' => '1997-04-12',
        'irs_jovem' => 'yes',
        'irs_jovem_year_number' => 2,
        'work_kind' => 'listed',
        'profession' => '1332 · Programadores informáticos',
        'expected_turnover' => 12000,
        'vat_answer' => 'exempt',
        'terms' => true,
        ...$overrides,
    ];
}

/** The answers of an account whose activity is not open yet: no fiscal questions. */
function waitingRegistrationData(array $overrides = []): array
{
    return [
        'name' => 'Rita Silva',
        'email' => 'rita@example.pt',
        'password' => 'uma-palavra-passe-longa',
        'activity_opened' => 'no',
        'nif' => '501964843',
        'terms' => true,
        ...$overrides,
    ];
}

function registrationWizard(array $overrides = [])
{
    return Livewire::test(RegisterAccount::class)->fillForm(registrationData($overrides));
}

function registeredSettings(): FinanceSettings
{
    CurrentAccount::set(Account::firstWhere('nif', '501964843'));

    return new FinanceSettings;
}

it('creates user and account from the wizard', function () {
    registrationWizard()
        ->call('register')
        ->assertHasNoFormErrors()
        ->assertRedirect(url('/rita-silva'));

    $user = User::firstWhere('email', 'rita@example.pt');
    $account = Account::firstWhere('nif', '501964843');

    expect($user->name)->toBe('Rita Silva')
        ->and(Hash::check('uma-palavra-passe-longa', $user->password))->toBeTrue()
        ->and($user->hasVerifiedEmail())->toBeFalse()
        ->and($user->terms_version)->toBe('2026-11-08')
        ->and($user->terms_accepted_at->toDateTimeString())->toBe('2026-11-08 10:00:00')
        // The account takes the person's name.
        ->and($account->name)->toBe('Rita Silva')
        ->and($account->slug)->toBe('rita-silva')
        ->and($account->owner()?->is($user))->toBeTrue()
        ->and($account->trial_ends_at->toDateString())->toBe('2026-11-22');

    CurrentAccount::run($account, function () {
        $settings = app(FinanceSettings::class);

        expect($settings->activityOpened())->toBeTrue()
            ->and($settings->accountingRegime())->toBe('simplified')
            ->and($settings->activityStartedOn()?->toDateString())->toBe('2026-03-01')
            ->and($settings->activityStartKind())->toBe('first')
            ->and($settings->ssEnrolledFrom())->toBeNull()
            ->and($settings->dependentIncomeYears())->toBe([2026])
            ->and($settings->irsCoefficient())->toBe(0.75)
            ->and($settings->irsJovem())->toBeTrue()
            ->and($settings->birthDate()?->toDateString())->toBe('1997-04-12')
            ->and($settings->irsJovemYearNumber(2026))->toBe(2)
            ->and($settings->ssRate())->toBe(0.214)
            ->and($settings->ssRelevantIncomeShare())->toBe(0.70)
            ->and($settings->ssContributionExempt())->toBeTrue()
            ->and($settings->ssExemptionReason())->toBe('accumulation')
            ->and($settings->vatExempt())->toBeTrue()
            ->and($settings->vatExemptionThreshold())->toBe(1500000)
            ->and($settings->paymentsExemptByDefault())->toBeTrue()
            ->and($settings->reverseChargeVatRate())->toBe(0.23)
            ->and($settings->isIncomplete())->toBeFalse()
            // A deduced exemption is a proposal: no confirmed fiscal profile yet.
            ->and(FiscalProfile::query()->count())->toBe(0);
    });

    $this->assertAuthenticatedAs($user);
    Notification::assertSentTo($user, VerifyEmail::class);

    // The account opens only after the email is confirmed: the dashboard sends to the prompt.
    $this->get(Dashboard::getUrl(tenant: $account))
        ->assertRedirect(Filament::getEmailVerificationPromptUrl());
})->group('ui');

it('leaves the bank out of the sign-up, for Tax settings later', function () {
    registrationWizard()
        ->assertFormFieldDoesNotExist('has_business_account')
        ->call('register')
        ->assertHasNoFormErrors();

    expect(registeredSettings()->hasBusinessAccount())->toBeFalse()
        ->and(registeredSettings()->accountNames())->toBe([]);
})->group('integration');

it('starts on the usual answers', function () {
    Livewire::test(RegisterAccount::class)->assertFormSet([
        'activity_opened' => 'yes',
        'activity_start_kind' => 'first',
        'accounting_regime' => 'simplified',
        'employment_scheme' => 'ss',
        'terms' => false,
    ]);
})->group('ui');

it('asks the password once', function () {
    Livewire::test(RegisterAccount::class)
        ->assertFormFieldExists('password')
        ->assertFormFieldDoesNotExist('passwordConfirmation');
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
        'situation' => ['self_only'],
        'irs_jovem' => 'no',
        'previous_activity_ended_on' => '2019-06-30',
        'ss_enrolled_from' => '2026-03-01',
    ])
        ->call('register')
        ->assertHasNoFormErrors();

    $settings = registeredSettings();

    expect($settings->activityStartKind())->toBe('first')
        ->and($settings->previousActivityEndedOn())->toBeNull()
        ->and($settings->ssEnrolledFrom())->toBeNull()
        ->and($settings->dependentIncomeYears())->toBe([])
        ->and($settings->ssContributionExempt())->toBeFalse()
        ->and($settings->ssExemptionReason())->toBeNull()
        ->and($settings->irsJovem())->toBeFalse()
        ->and($settings->irsJovemYearNumber(2026))->toBeNull();
})->group('integration');

it('asks for the year number with IRS Jovem', function () {
    registrationWizard(['irs_jovem_year_number' => null])
        ->call('register')
        ->assertHasFormErrors(['irs_jovem_year_number' => 'required']);

    expect(Account::where('nif', '501964843')->exists())->toBeFalse();
})->group('integration');

it('does not ask about IRS Jovem after 35', function () {
    registrationWizard(['birth_date' => '1990-01-01', 'irs_jovem' => 'yes', 'irs_jovem_year_number' => null])
        ->call('register')
        ->assertHasNoFormErrors();

    expect(registeredSettings()->irsJovem())->toBeFalse();
})->group('integration');

it('asks for the Social Security date after a restart (SS012)', function () {
    registrationWizard(['activity_start_kind' => 'restart', 'ss_enrolled_from' => null])
        ->goToWizardStep(3)
        ->assertHasFormErrors(['ss_enrolled_from' => 'required'])
        ->assertWizardCurrentStep(2);
})->group('integration');

it('takes a restart without the cessation date, which few freelancers can find', function () {
    registrationWizard(['activity_start_kind' => 'restart', 'previous_activity_ended_on' => null, 'ss_enrolled_from' => '2026-03-01'])
        ->call('register')
        ->assertHasNoFormErrors();

    $settings = registeredSettings();

    expect($settings->activityStartKind())->toBe('restart')
        ->and($settings->previousActivityEndedOn())->toBeNull()
        ->and($settings->ssEnrolledFrom()?->toDateString())->toBe('2026-03-01');
})->group('integration');

it('does not take “only self-employed” with a job or a pension', function () {
    registrationWizard(['situation' => ['employment', 'self_only']])
        ->goToWizardStep(4)
        ->assertHasFormErrors(['situation'])
        ->assertWizardCurrentStep(3);
})->group('integration');

it('says where to find each fiscal answer', function () {
    registrationWizard()
        ->assertSee('Regime de tributação')
        ->assertSee('Enquadramento em IVA')
        ->assertSee('Na média dos últimos três meses. É o valor do IAS em 2026.')
        ->assertSee(Money::format(53713));
})->group('integration');

it('sums up the tax answers before creating the account', function () {
    registrationWizard()
        ->goToWizardStep(5)
        ->assertSee('Passo 5 de 5 · O teu perfil fiscal')
        ->assertSee('1332 · Programadores informáticos · por conta própria desde março de 2026')
        ->assertSee('Em 2026 não pagas Segurança Social nem cobras IVA.')
        ->assertSee('Não pagas, por agora')
        ->assertSee('Provável · confirma')
        ->assertSee('fica abaixo de '.Money::format(214852).' por mês')
        ->assertSee('Não tem obrigação de contribuir')
        ->assertSee('Sem a redução dos primeiros anos, porque tens salário em 2026.')
        ->assertSee('abaixo do limite de '.Money::format(1500000).' do art. 53.º')
        ->assertSee('1 de março de 2027');
})->group('integration');

it('shows in the side panel what it works out from the steps already answered', function () {
    registrationWizard()
        ->assertSee('O que já sabemos')
        ->assertSee('Desde mar. 2027')
        ->assertSee('Isento (provável)')
        ->assertSee('Dispensada');
})->group('integration');

it('confirms the tax profile when the user has checked the exemption', function () {
    registrationWizard(['exemption_confirmed' => true])
        ->call('register')
        ->assertHasNoFormErrors();

    CurrentAccount::set(Account::firstWhere('nif', '501964843'));
    $profile = FiscalProfile::query()->sole();

    expect($profile->confirmed_at)->not->toBeNull()
        ->and($profile->ss_contribution_exempt)->toBeTrue()
        ->and($profile->has_dependent_employment)->toBeTrue()
        ->and($profile->vat_exempt)->toBeNull()
        ->and($profile->vat_number)->toBe('501964843')
        ->and($profile->confirmed_by)->toBe(User::firstWhere('email', 'rita@example.pt')->getKey());
})->group('integration');

it('stores no VAT limit when the account is not VAT exempt', function () {
    registrationWizard(['vat_answer' => 'normal'])
        ->call('register')
        ->assertHasNoFormErrors();

    $settings = registeredSettings();

    expect($settings->vatExempt())->toBeFalse()
        ->and($settings->paymentsExemptByDefault())->toBeFalse()
        ->and($settings->vatExemptionThreshold())->toBe(1500000);
})->group('integration');

it('creates an account on hold, without fiscal questions, when the activity is not opened yet', function () {
    Livewire::test(RegisterAccount::class)
        ->fillForm(waitingRegistrationData())
        ->assertSee('Passo 3 de 3')
        ->assertSee('Guardamos a conta à espera')
        ->assertSee('Os 14 dias grátis começam hoje, com o que já podes usar.')
        ->assertDontSee('Como é o teu 2026?')
        ->call('register')
        ->assertHasNoFormErrors()
        ->assertRedirect(url('/rita-silva'));

    $settings = registeredSettings();

    expect($settings->activityOpened())->toBeFalse()
        ->and($settings->estimatesTaxes())->toBeFalse()
        ->and($settings->isIncomplete())->toBeFalse()
        ->and($settings->activityStartedOn())->toBeNull()
        ->and(Account::firstWhere('nif', '501964843')->trial_ends_at->toDateString())->toBe('2026-11-22');
})->group('integration');

it('creates an account with organised accounting, asking only the VAT', function () {
    Livewire::test(RegisterAccount::class)
        ->fillForm(registrationData(['accounting_regime' => 'organised', 'organised_vat_answer' => 'exempt', 'situation' => null, 'work_kind' => null]))
        ->assertSee('Passo 3 de 3')
        ->assertSee('Entras sem a parte dos impostos')
        ->call('register')
        ->assertHasNoFormErrors();

    $settings = registeredSettings();

    expect($settings->accountingRegime())->toBe('organised')
        ->and($settings->estimatesTaxes())->toBeFalse()
        ->and($settings->vatExempt())->toBeTrue()
        ->and($settings->activityStartedOn()?->toDateString())->toBe('2026-03-01')
        ->and($settings->ssContributionExempt())->toBeFalse();
})->group('integration');

it('counts the steps of each path, with and without the payment step', function (bool $payment, array $answers, string $count) {
    config(['contiu.signup_payment' => $payment]);

    Livewire::test(RegisterAccount::class)
        ->fillForm($answers)
        ->assertSee($count)
        ->assertSee($payment ? 'Começa com 14 dias grátis' : 'Criar conta')
        ->assertDontSee($payment ? 'Criar conta</span>' : 'Começa com 14 dias grátis', escape: false);
})->with([
    'activity open, no payment' => [false, ['activity_opened' => 'yes'], 'Passo 1 de 5'],
    'on hold, no payment' => [false, ['activity_opened' => 'no'], 'Passo 1 de 3'],
    'organised, no payment' => [false, ['activity_opened' => 'yes', 'accounting_regime' => 'organised'], 'Passo 1 de 3'],
    'activity open, with payment' => [true, ['activity_opened' => 'yes'], 'Passo 6 de 6'],
    'on hold, with payment' => [true, ['activity_opened' => 'no'], 'Passo 4 de 4'],
    'organised, with payment' => [true, ['activity_opened' => 'yes', 'accounting_regime' => 'organised'], 'Passo 4 de 4'],
])->group('integration');

it('starts the trial without a card and never calls Stripe while the payment step is off', function () {
    $this->mock(StartSignupSubscription::class)->shouldNotReceive('handle');

    registrationWizard()
        ->assertFormFieldDoesNotExist('plan')
        ->assertFormFieldDoesNotExist('payment_method')
        ->call('register')
        ->assertHasNoFormErrors();

    expect(Account::firstWhere('nif', '501964843')->onGenericTrial())->toBeTrue();
})->group('integration');

it('asks for the plan, the card and the invoice address with the payment step on', function () {
    config(['contiu.signup_payment' => true]);

    registrationWizard(['plan' => null, 'payment_method' => null, 'billing_address' => null])
        ->call('register')
        ->assertHasFormErrors(['plan' => 'required', 'payment_method' => 'required', 'billing_address' => 'required']);

    expect(User::where('email', 'rita@example.pt')->exists())->toBeFalse();
})->group('integration');

it('subscribes the new account to the chosen plan with the card from Stripe', function () {
    config(['contiu.signup_payment' => true]);

    $this->mock(StartSignupSubscription::class)
        ->shouldReceive('handle')
        ->once()
        ->withArgs(fn (Account $account, string $plan, string $paymentMethod, array $address): bool => $account->nif === '501964843'
            && $account->trial_ends_at->toDateString() === '2026-11-22'
            && $plan === 'monthly'
            && $paymentMethod === 'pm_card_visa'
            && $address === ['line1' => 'Rua do Porto 15, 2.º', 'postal_code' => '4050-123', 'city' => 'Porto']);

    registrationWizard([
        'plan' => 'monthly',
        'payment_method' => 'pm_card_visa',
        'billing_address' => 'Rua do Porto 15, 2.º',
        'billing_postal_code' => '4050-123',
        'billing_city' => 'Porto',
    ])
        ->call('register')
        ->assertHasNoFormErrors();

    expect(Account::where('nif', '501964843')->exists())->toBeTrue();
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

it('refuses a negative expected turnover', function () {
    registrationWizard(['expected_turnover' => -1])
        ->call('register')
        ->assertHasFormErrors(['expected_turnover' => 'min']);

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
        ->assertSee('Cria a tua conta')
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
        // White, on the green side panel.
        ->assertSee('brand/contiu/contiu-logo-white.svg', escape: false);
})->group('integration');
