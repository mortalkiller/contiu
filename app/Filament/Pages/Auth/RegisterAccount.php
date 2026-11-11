<?php

namespace App\Filament\Pages\Auth;

use App\Actions\ConfirmFiscalProfile;
use App\Actions\CreateAccount;
use App\Actions\StartSignupSubscription;
use App\Enums\Language;
use App\Filament\Pages\AccountSubscription;
use App\Filament\Support\SignupQuestions;
use App\Models\Account;
use App\Models\User;
use App\Rules\PortugueseNif;
use App\Support\CurrentAccount;
use App\Support\Money;
use App\Support\TaxProfileDeduction;
use Filament\Auth\Pages\Register;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Stripe\Exception\ApiErrorException;

/**
 * Self-service sign-up, as in the "Registo" mocks: a green side panel with the steps and what
 * Contiu has worked out so far, and one question per screen in plain language. The answers become
 * the fiscal settings through TaxProfileDeduction. Three paths, chosen on the activity step:
 *
 * - activity open, simplified regime: account, activity, situation, work, tax profile;
 * - activity not opened yet: account, activity, account on hold (no fiscal questions until
 *   "Já abri atividade" on the dashboard, OpenActivity);
 * - organised accounting: account, activity, no taxes (only the VAT).
 *
 * With contiu.signup_payment on, each path ends with the plan and the card (Stripe's Payment
 * Element). The user and the account are created together (CreateAccount: owner, trial, fiscal
 * settings, base categories); Filament then sends the verification email.
 */
class RegisterAccount extends Register
{
    protected string $view = 'filament.pages.auth.register-account';

    protected static string $layout = 'filament.pages.auth.signup-layout';

    /** The label of each step in the side panel. */
    private const STEP_LABELS = [
        'account' => 'Account',
        'activity' => 'Activity',
        'situation' => 'Situation',
        'work' => 'Work',
        'profile' => 'Tax profile',
        'waiting' => 'Account on hold',
        'organised' => 'No taxes',
        'payment' => 'Payment',
    ];

    /** Whether the plan and card are a sign-up step (contiu.signup_payment). */
    public static function takesPayment(): bool
    {
        return (bool) config('contiu.signup_payment');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Create account');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('Create your Contiu account');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Wizard::make($this->getSteps())
                ->hiddenHeader()
                ->alpineSubmitHandler(self::takesPayment() ? "\$dispatch('contiu-signup-pay')" : '$wire.register()')
                ->contained(false)
                ->extraAttributes(['class' => 'contiu-signup-wizard']),
        ]);
    }

    /** The wizard carries its own buttons, in each step's foot. */
    public function getFormContentComponent(): Component
    {
        return EmbeddedSchema::make('form');
    }

    /**
     * The steps of the path the answers lead to, in order.
     *
     * @return list<string>
     */
    public function stepNames(): array
    {
        $steps = match ($this->deduction()->path()) {
            'waiting' => ['account', 'activity', 'waiting'],
            'organised' => ['account', 'activity', 'organised'],
            default => ['account', 'activity', 'situation', 'work', 'profile'],
        };

        return self::takesPayment() ? [...$steps, 'payment'] : $steps;
    }

    /** What the answers given so far lead to. */
    public function deduction(): TaxProfileDeduction
    {
        return TaxProfileDeduction::from(SignupQuestions::answers($this->data ?? [], turnoverInEuros: true));
    }

    /** @return array<int, Step> */
    protected function getSteps(): array
    {
        return [
            $this->step('account', [
                $this->getNameFormComponent()
                    ->label(__('Your name')),
                $this->getEmailFormComponent()
                    ->label(__('Email'))
                    ->helperText(__('We send you a link to confirm it.')),
                TextInput::make('password')
                    ->label(__('Password'))
                    ->helperText(__('At least 8 characters.'))
                    ->password()
                    ->revealable(filament()->arePasswordsRevealable())
                    ->required()
                    ->rule(Password::default())
                    ->showAllValidationMessages()
                    ->dehydrateStateUsing(fn (#[SensitiveParameter] $state) => Hash::make($state))
                    ->validationAttribute(__('password'))
                    ->autocomplete('new-password'),
            ]),

            $this->step('activity', [
                SignupQuestions::activityOpened(),
                Group::make([
                    ...SignupQuestions::activityDetails(onlyIfOpened: true),
                    TextInput::make('nif')
                        ->label(__('Your tax ID'))
                        ->inlineLabel()
                        ->required()
                        ->rule(new PortugueseNif)
                        ->unique(Account::class, 'nif')
                        ->validationMessages(['unique' => __('An account already uses this tax ID.')])
                        ->extraInputAttributes(['inputmode' => 'numeric', 'autocomplete' => 'off']),
                ])->extraAttributes(['class' => 'contiu-signup-lines']),
            ]),

            $this->step('situation', SignupQuestions::situation()),

            $this->step('work', SignupQuestions::work()),

            $this->step('profile', [
                View::make('filament.pages.auth.partials.signup-profile')
                    ->viewData(fn (): array => $this->profileData()),
                // "Já confirmei" on the Social Security card: the exemption is then confirmed.
                Hidden::make('exemption_confirmed')->default(false),
            ]),

            $this->step('waiting', [
                View::make('filament.pages.auth.partials.signup-lists')
                    ->viewData(fn (): array => [
                        'lists' => [
                            [__('You can use now'), [__('Clients and suppliers'), __('Jobs and quotes'), __('Expenses')], false],
                            [__('When you open your activity'), [__('Invoices and receipts'), __('Taxes and estimates'), __('Tax calendar')], true],
                        ],
                        'notice' => $this->trialNotice(waiting: true),
                    ]),
            ]),

            $this->step('organised', [
                View::make('filament.pages.auth.partials.signup-lists')
                    ->viewData(fn (): array => [
                        'lists' => [
                            [__('You can use'), [__('Clients, jobs and quotes'), __('Invoices and receipts'), __('Expenses and cash')], false],
                            [__('Not available yet'), [__('IRS and Social Security estimate'), __('Tax calendar'), __('Yearly tax summary')], true],
                        ],
                        'notice' => null,
                    ]),
                Group::make([
                    Text::make(__('Just one question, for the invoices'))->extraAttributes(['class' => 'contiu-signup-question']),
                    Group::make([SignupQuestions::organisedVat()])->extraAttributes(['class' => 'contiu-signup-lines']),
                ])->extraAttributes(['class' => 'contiu-signup-block']),
                View::make('filament.pages.auth.partials.signup-notice')
                    ->viewData(fn (): array => ['notice' => $this->trialNotice(waiting: false)]),
            ]),

            $this->step('payment', [
                Radio::make('plan')
                    ->label(__('Plan'))
                    ->hiddenLabel()
                    ->options(fn (): array => [
                        'yearly' => __('Yearly · :amount a year', ['amount' => Money::format(AccountSubscription::PLANS['yearly']['amount'])]),
                        'monthly' => __('Monthly · :amount a month', ['amount' => Money::format(AccountSubscription::PLANS['monthly']['amount'])]),
                    ])
                    ->descriptions(fn (): array => [
                        'yearly' => __('No VAT. The same as :amount per month.', ['amount' => Money::format(intdiv(AccountSubscription::PLANS['yearly']['amount'], 12))]),
                        'monthly' => __('No VAT. :amount after one year.', ['amount' => Money::format(12 * AccountSubscription::PLANS['monthly']['amount'])]),
                    ])
                    ->default('yearly')
                    ->required()
                    ->in(array_keys(AccountSubscription::PLANS))
                    ->columns(2)
                    ->extraAttributes(['class' => 'fap-radio-cards']),
                Group::make([
                    Text::make(__('Payment details'))->extraAttributes(['class' => 'contiu-signup-question']),
                    View::make('filament.pages.auth.partials.signup-payment-element')
                        ->viewData(fn (): array => [
                            'publishableKey' => config('cashier.key'),
                            'locale' => Language::resolve(app()->getLocale()) === Language::EnglishUnitedStates ? 'en' : 'pt',
                        ]),
                    // The PaymentMethod Stripe.js creates from the card, set just before register().
                    Hidden::make('payment_method')
                        ->required()
                        ->rule('starts_with:pm_')
                        ->validationMessages(['required' => __('Enter your card details.')]),
                ])->extraAttributes(['class' => 'contiu-signup-block']),
                Group::make([
                    Text::make(__('Invoice details'))->extraAttributes(['class' => 'contiu-signup-question']),
                    Text::make(fn (): string => __('In the name of :name · Tax ID :nif.', ['name' => $this->data['name'] ?? '', 'nif' => $this->data['nif'] ?? '']))
                        ->extraAttributes(['class' => 'contiu-signup-hint']),
                    TextInput::make('billing_address')->label(__('Address'))->autocomplete('street-address')->required()->maxLength(255),
                    Group::make([
                        TextInput::make('billing_postal_code')->label(__('Postal code'))->autocomplete('postal-code')->required()->regex('/\A\d{4}-\d{3}\z/'),
                        TextInput::make('billing_city')->label(__('Town'))->required()->maxLength(120),
                    ])->columns(2),
                ])->extraAttributes(['class' => 'contiu-signup-block']),
            ]),
        ];
    }

    /**
     * A screen of the sign-up: the side panel, then the heading, the questions and the buttons.
     * Only the steps of the current path are part of the form.
     *
     * @param  array<int, Component>  $components
     */
    private function step(string $name, array $components): Step
    {
        return Step::make(__(self::STEP_LABELS[$name]))
            ->visible(fn (): bool => in_array($name, $this->stepNames(), true))
            ->schema([
                View::make('filament.pages.auth.partials.signup-side')
                    ->viewData(fn (): array => $this->sideData($name)),
                Group::make([
                    View::make('filament.pages.auth.partials.signup-heading')
                        ->viewData(fn (): array => $this->headingData($name)),
                    ...$components,
                    ...($this->isLastStep($name) ? [$this->termsField()] : []),
                    View::make('filament.pages.auth.partials.signup-foot')
                        ->viewData(fn (): array => $this->footData($name)),
                ])->extraAttributes(['class' => 'contiu-signup-main']),
            ])
            ->extraAttributes(['class' => 'contiu-signup-step']);
    }

    /** The terms go on the last step of every path. */
    private function isLastStep(string $name): bool
    {
        return self::takesPayment() ? $name === 'payment' : in_array($name, ['profile', 'waiting', 'organised'], true);
    }

    private function termsField(): Checkbox
    {
        return Checkbox::make('terms')
            ->label(new HtmlString(__('I have read and accept the :terms and the :privacy.', [
                'terms' => '<a href="'.e(url('/termos')).'" target="_blank" class="font-medium underline">'.e(__('Terms of Service')).'</a>',
                'privacy' => '<a href="'.e(url('/privacidade')).'" target="_blank" class="font-medium underline">'.e(__('Privacy Policy')).'</a>',
            ])))
            ->accepted()
            ->validationMessages(['accepted' => __('You must accept the Terms of Service and Privacy Policy.')])
            // Recorded on the user (when and which version), not stored as typed.
            ->dehydrated(false);
    }

    /** @return array<string, mixed> */
    private function sideData(string $current): array
    {
        $names = $this->stepNames();
        $index = (int) array_search($current, $names, true);
        $previous = $names[$index - 1] ?? null;

        $steps = array_map(fn (string $name, int $position): array => [
            'label' => __(self::STEP_LABELS[$name]),
            'number' => $position + 1,
            'state' => match (true) {
                $position < $index => 'done',
                $position === $index => 'current',
                default => null,
            },
        ], $names, array_keys($names));

        return [
            'logo' => asset('brand/contiu/contiu-logo-white.svg'),
            'steps' => $steps,
            'panel' => match ($current) {
                'profile' => null,
                'payment' => $this->planPanel(),
                'waiting' => ['title' => __('Your account'), 'empty' => null, 'items' => [
                    ['label' => __('Name'), 'value' => $this->data['name'] ?? '', 'new' => false],
                    ['label' => __('Email'), 'value' => $this->data['email'] ?? '', 'new' => false],
                    ['label' => __('Activity'), 'value' => __('Not opened yet'), 'new' => true],
                ]],
                default => [
                    'title' => $current === 'organised' ? __('Your account') : __('What we know so far'),
                    'empty' => __('As you answer, what Contiu works out for your taxes shows up here.'),
                    'items' => collect($this->deduction()->known())
                        ->filter(fn (array $item): bool => (int) array_search($item['step'], $names, true) <= $index && in_array($item['step'], $names, true))
                        ->map(fn (array $item): array => [...$item, 'new' => $item['step'] === $previous || ($current === 'organised' && $item['step'] === 'organised')])
                        ->values()
                        ->all(),
                ],
            },
            'foot' => match ($current) {
                'profile' => __('You can change any value later, in Tax settings. The tax estimate only uses the values you confirm.'),
                'payment' => __('Cancel or change plan whenever you like, in Subscription.'),
                default => null,
            },
        ];
    }

    /**
     * "O teu plano": what is charged today and when the trial ends, for each plan (the panel shows
     * the one chosen, in the browser).
     *
     * @return array<string, mixed>
     */
    private function planPanel(): array
    {
        $chargedOn = $this->trialEndsOn();

        return ['title' => __('Your plan'), 'empty' => null, 'plans' => collect(AccountSubscription::PLANS)->map(fn (array $plan, string $key): array => [
            ['label' => __('Plan'), 'value' => __($plan['label']), 'new' => false],
            ['label' => __('Today'), 'value' => Money::format(0), 'new' => true],
            ['label' => __('On :date', ['date' => $chargedOn]), 'value' => Money::format($plan['amount']), 'new' => false],
            ['label' => __('Renews'), 'value' => $key === 'yearly' ? __('Every year') : __('Every month'), 'new' => false],
        ])->all(), 'items' => []];
    }

    /** @return array{eyebrow: string, title: string, lede: ?string, hidden: bool} */
    private function headingData(string $name): array
    {
        $names = $this->stepNames();
        $eyebrow = __('Step :number of :total', ['number' => (int) array_search($name, $names, true) + 1, 'total' => count($names)]);
        $year = (int) now()->year;

        [$title, $lede] = match ($name) {
            'account' => [__('Create your account'), __('Two minutes. We ask about your situation, in plain words, and we do the tax maths.')],
            'activity' => [__('Have you opened your activity with the Tax Authority?'), __('If not yet, we keep your account on hold.')],
            'situation' => [__('What is your :year like?', ['year' => $year]), __('Choose all that apply. It changes Social Security and IRS.')],
            'work' => [__('What do you do?'), __('This gives the IRS coefficient, the Social Security rate and VAT.')],
            'profile' => ['', null],
            'waiting' => [__('We keep your account on hold'), __('You have not opened your activity yet, so we ask nothing about taxes. When you do, press “I have opened my activity” and answer the rest in two minutes.')],
            'organised' => [__('You come in without the tax part'), __('Contiu does not work out taxes with organised accounting yet. Everything else works, and we let you know when that part is ready.')],
            'payment' => [__('Start with :days free days', ['days' => config('contiu.trial_days')]), __('You pay nothing today. The first charge is on :date and you can cancel before then.', ['date' => $this->trialEndsOn()])],
        };

        // The profile screen has its own heading, with the person's name.
        return ['eyebrow' => $eyebrow, 'title' => $title, 'lede' => $lede, 'hidden' => $name === 'profile'];
    }

    /** @return array{first: bool, last: bool, nextLabel: string, submitLabel: string, loginUrl: string} */
    private function footData(string $name): array
    {
        $names = $this->stepNames();

        return [
            'first' => $names[0] === $name,
            'last' => end($names) === $name,
            'nextLabel' => $name === 'work' ? __('See my profile') : __('Continue'),
            'submitLabel' => self::takesPayment() ? __('Start :days free days', ['days' => config('contiu.trial_days')]) : __('Create account'),
            'loginUrl' => Filament::getLoginUrl(),
        ];
    }

    /** @return array<string, mixed> */
    private function profileData(): array
    {
        $names = $this->stepNames();

        return SignupQuestions::profileViewData(
            $this->deduction(),
            $this->data ?? [],
            eyebrow: __('Step :number of :total', ['number' => (int) array_search('profile', $names, true) + 1, 'total' => count($names)]).' · '.__('Your tax profile'),
            stepNames: $names,
        );
    }

    /** The day the free trial ends, as the date format of the language. */
    private function trialEndsOn(): string
    {
        return now()->addDays((int) config('contiu.trial_days'))->format(Language::resolve(app()->getLocale())->dateFormat());
    }

    private function trialNotice(bool $waiting): string
    {
        $days = (int) config('contiu.trial_days');

        if (! self::takesPayment()) {
            return $waiting
                ? __('The :days free days start today, with what you can already use. Then you choose a plan, in Subscription.', ['days' => $days])
                : __('The :days free days start today. Then you choose a plan, in Subscription.', ['days' => $days]);
        }

        return $waiting
            ? __('The :days free days start today, with what you can already use. The first charge is on :date, even if you have not opened your activity yet. Next you choose the plan.', ['days' => $days, 'date' => $this->trialEndsOn()])
            : __('The :days free days start today. Next you choose the plan.', ['days' => $days]);
    }

    /**
     * The user and the account are created together: if the account (or, with the payment step,
     * the subscription) fails, no user is left behind without one. The account takes the
     * person's name.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRegistration(#[SensitiveParameter] array $data): Model
    {
        return DB::transaction(function () use ($data): User {
            $user = new User;
            $user->forceFill([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'terms_accepted_at' => now(),
                'terms_version' => config('contiu.terms_version'),
            ])->save();

            $deduction = TaxProfileDeduction::from(SignupQuestions::answers($data, turnoverInEuros: false));

            $account = app(CreateAccount::class)->handle($user, [
                'name' => $data['name'],
                'nif' => $data['nif'],
                ...$deduction->settings(),
                // The bank left the sign-up: it is set in Tax settings, off being the common case.
                'has_business_account' => false,
            ]);

            // "Já confirmei": the user checked the exemption in Segurança Social Direta.
            if (($data['exemption_confirmed'] ?? false) && ($confirmation = $deduction->exemptionConfirmation($data['name'], $data['nif'])) !== null) {
                CurrentAccount::run($account, fn () => app(ConfirmFiscalProfile::class)->handle($user, $confirmation));
            }

            if (self::takesPayment()) {
                $this->startSubscription($account, $data);
            }

            return $user;
        });
    }

    /** @param array<string, mixed> $data */
    private function startSubscription(Account $account, array $data): void
    {
        try {
            app(StartSignupSubscription::class)->handle($account, $data['plan'], $data['payment_method'], [
                'line1' => $data['billing_address'],
                'postal_code' => $data['billing_postal_code'],
                'city' => $data['billing_city'],
            ]);
        } catch (ApiErrorException $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'data.payment_method' => __('Stripe did not accept the card. Check the details or try another card.'),
            ]);
        }
    }
}
