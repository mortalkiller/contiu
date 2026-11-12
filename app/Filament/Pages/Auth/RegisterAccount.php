<?php

namespace App\Filament\Pages\Auth;

use App\Actions\ConfirmFiscalProfile;
use App\Actions\CreateAccount;
use App\Actions\StartSignupSubscription;
use App\Enums\Language;
use App\Filament\Pages\AccountSubscription;
use App\Filament\Support\SignupQuestions;
use App\Http\Middleware\ApplyGuestLanguage;
use App\Models\Account;
use App\Models\User;
use App\Rules\PortugueseNif;
use App\Support\AccountantService;
use App\Support\CurrentAccount;
use App\Support\Money;
use App\Support\TaxProfileDeduction;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
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
use Filament\Support\Enums\GridDirection;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Stripe\Exception\ApiErrorException;
use Throwable;

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
 * Every path then asks about Contiu's certified accountant (AccountantService), an extra
 * monthly service; organised accounting needs one, so it asks whose. With contiu.signup_payment
 * on, each path ends with the plan and the card (Stripe's Payment Element). The user and the account are created together (CreateAccount: owner, trial, fiscal
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
        'accountant' => 'Accountant',
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
                // Filament scrolls to the step in the header, which is hidden here: back to the top instead.
                ->extraAlpineAttributes(['x-init' => "\$watch('step', () => window.scrollTo({ top: 0, behavior: 'smooth' }))"])
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

        return self::takesPayment() ? [...$steps, 'accountant', 'payment'] : [...$steps, 'accountant'];
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
                // GDPR art. 13: the information is given when the data is collected, here.
                Text::make(new HtmlString(__('We use this data to create your account and work out your taxes, as the :privacy explains.', [
                    'privacy' => '<a href="'.e(url('/privacidade')).'" target="_blank" class="font-medium underline">'.e(__('Privacy Policy')).'</a>',
                ])))->extraAttributes(['class' => 'contiu-signup-hint']),
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

            $this->step('accountant', [
                Radio::make('accountant')
                    ->label(__('Certified accountant'))
                    ->hiddenLabel()
                    ->options(fn (): array => $this->accountantOptions())
                    ->descriptions(fn (): array => $this->accountantDescriptions())
                    ->default('contiu')
                    ->required()
                    ->in(fn (): array => array_keys($this->accountantOptions()))
                    ->columns(2)
                    ->gridDirection(GridDirection::Row)
                    ->extraAttributes(['class' => 'fap-radio-cards']),
                // Whoever already has one says No: the invitation is in Subscription, at no cost.
                Text::make(__('Already have an accountant? Invite them to your Contiu in Subscription and accountants, at no extra cost.'))
                    ->visible(fn (): bool => $this->deduction()->path() !== 'organised')
                    ->extraAttributes(['class' => 'contiu-signup-hint']),
                View::make('filament.pages.auth.partials.signup-accountant')
                    ->viewData(fn (): array => $this->accountantData()),
            ]),

            $this->step('payment', [
                Radio::make('plan')
                    ->label(__('Plan'))
                    ->hiddenLabel()
                    ->options(fn (): array => [
                        'yearly' => __('Yearly · :amount a year', ['amount' => Money::format(AccountSubscription::priceWithVat('yearly'))]),
                        'monthly' => __('Monthly · :amount a month', ['amount' => Money::format(AccountSubscription::priceWithVat('monthly'))]),
                    ])
                    ->descriptions(fn (): array => [
                        'yearly' => __('VAT included (:net + VAT). The same as :amount per month.', ['net' => Money::format(AccountSubscription::PLANS['yearly']['amount']), 'amount' => Money::format(intdiv(AccountSubscription::priceWithVat('yearly'), 12))]),
                        'monthly' => __('VAT included (:net + VAT). :amount after one year.', ['net' => Money::format(AccountSubscription::PLANS['monthly']['amount']), 'amount' => Money::format(12 * AccountSubscription::priceWithVat('monthly'))]),
                    ])
                    ->default('yearly')
                    ->required()
                    ->in(array_keys(AccountSubscription::PLANS))
                    ->columns(2)
                    ->gridDirection(GridDirection::Row)
                    ->extraAttributes(['class' => 'fap-radio-cards']),
                View::make('filament.pages.auth.partials.signup-charges')
                    ->visible(fn (): bool => $this->accountantPlan() !== null)
                    ->viewData(fn (): array => $this->chargesData()),
                Group::make([
                    Text::make(__('Payment details'))->extraAttributes(['class' => 'contiu-signup-question']),
                    View::make('filament.pages.auth.partials.signup-payment-element')
                        ->viewData(fn (): array => [
                            'publishableKey' => config('cashier.key'),
                            'locale' => Language::resolve(app()->getLocale()) === Language::EnglishUnitedStates ? 'en' : 'pt',
                        ]),
                    // The payment method of the SetupIntent Stripe.js confirmed, set just before register().
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
                    ...($this->isLastStep($name) ? $this->closingFields($name) : []),
                    View::make('filament.pages.auth.partials.signup-foot')
                        ->viewData(fn (): array => $this->footData($name)),
                ])->extraAttributes(['class' => 'contiu-signup-main']),
            ])
            ->extraAttributes(['class' => 'contiu-signup-step']);
    }

    /**
     * The end of every path: with the payment step, what will be charged and when (it renews by
     * itself until cancelled); then the terms (required) and the news by email (optional, off).
     *
     * @return array<int, Component>
     */
    private function closingFields(string $name): array
    {
        return array_values(array_filter([
            $name === 'payment' ? View::make('filament.pages.auth.partials.signup-notice')
                ->viewData(fn (): array => ['notice' => $this->renewalNotice()]) : null,
            $this->termsField(),
            Checkbox::make('marketing')
                ->label(__('I want news and tips from Contiu by email. Optional.'))
                ->default(false),
        ]));
    }

    /**
     * What pressing the button commits to. The plan is chosen on the same screen without asking the
     * server, so the notice names both prices rather than following the choice.
     */
    private function renewalNotice(): string
    {
        $replacements = [
            'button' => __('Start :days free days', ['days' => config('contiu.trial_days')]),
            'date' => $this->trialEndsOn(),
            'yearly' => Money::format(AccountSubscription::priceWithVat('yearly')),
            'monthly' => Money::format(AccountSubscription::priceWithVat('monthly')),
        ];

        if (($accountant = $this->accountantPlan()) !== null) {
            return __('By pressing “:button”, you authorise the charge of the chosen plan and of the certified accountant on :date (:yearly a year or :monthly a month, plus :accountant a month for the accountant, VAT included) and then at each renewal, until you cancel in Subscription. We email you 3 days before the first charge.', [
                ...$replacements,
                'accountant' => Money::format(AccountantService::priceWithVat($accountant)),
            ]);
        }

        return __('By pressing “:button”, you authorise the charge of the chosen plan on :date (:yearly a year or :monthly a month, VAT included) and then at each renewal, until you cancel in Subscription. We email you 3 days before the first charge.', $replacements);
    }

    /**
     * The certified-accountant service the answers chose (AccountantService plan), or null when the
     * account does not want Contiu's: organised accounting has its own price.
     */
    public function accountantPlan(): ?string
    {
        if (($this->data['accountant'] ?? null) !== 'contiu') {
            return null;
        }

        return AccountantService::planFor($this->deduction()->path() === 'organised' ? 'organised' : null);
    }

    /** @return array<string, string> */
    private function accountantOptions(): array
    {
        return $this->deduction()->path() === 'organised'
            ? ['contiu' => __('I want Contiu’s accountant'), 'own' => __('I already have an accountant')]
            : ['contiu' => __('Yes, I want Contiu’s'), 'none' => __('No, I will handle it')];
    }

    /** @return array<string, string> */
    private function accountantDescriptions(): array
    {
        $plan = AccountantService::planFor($this->deduction()->path() === 'organised' ? 'organised' : null);
        $price = __(':amount a month, VAT included (:net + VAT). Only after the :days free days.', [
            'amount' => Money::format(AccountantService::priceWithVat($plan)),
            'net' => Money::format(AccountantService::PLANS[$plan]['amount']),
            'days' => config('contiu.trial_days'),
        ]);

        return $plan === 'organised'
            ? ['contiu' => $price, 'own' => __('Invite them to your Contiu in Subscription and accountants: they see your invoices and expenses, at no extra cost.')]
            : ['contiu' => $price, 'none' => __('Contiu reminds you of every deadline. You can add the accountant later, in Subscription.')];
    }

    /**
     * What Contiu's certified accountant does, by path: organised accounting adds the accounts and
     * the IES; an account on hold, the help to open the activity.
     *
     * @return array<string, mixed>
     */
    private function accountantData(): array
    {
        $path = $this->deduction()->path();

        return [
            'includesTitle' => __('What Contiu’s accountant includes'),
            'includes' => array_values(array_filter([
                __('A certified accountant just for you, who knows your activity'),
                $path === 'waiting' ? __('Help to open your activity with the Tax Authority') : null,
                __('The periodic VAT returns'),
                __('The Social Security quarterly declaration'),
                __('The IRS return, with the activity annex'),
                $path === 'organised' ? __('The organised accounts and the yearly accounting and tax information return (IES)') : null,
                __('Support all year round: ask them whatever you need'),
            ])),
            'steps' => [
                [__('You choose now'), __('You pay nothing in the :days free days, not even for the accountant.', ['days' => config('contiu.trial_days')])],
                [__('We introduce the accountant'), __('A certified accountant from a Contiu partner joins your account, with accountant access.')],
                [__('They handle it with you'), __('From :date it is charged with your subscription. Cancel whenever you like, in Subscription.', ['date' => $this->trialEndsOn()])],
            ],
            'notice' => $path === 'organised'
                ? __('If no accountant is registered for you with the Tax Authority yet, they handle the appointment. Until then, Contiu works as today: invoices, expenses and cash.')
                : null,
        ];
    }

    /**
     * "O que vais pagar" on the payment step when the accountant is chosen: today, the plan (as
     * chosen in the browser), the accountant and the total of the first charge.
     *
     * @return array<string, mixed>
     */
    private function chargesData(): array
    {
        $accountant = AccountantService::priceWithVat((string) $this->accountantPlan());

        return [
            'chargedOn' => $this->trialEndsOn(),
            'accountant' => $accountant,
            'accountantNet' => AccountantService::PLANS[(string) $this->accountantPlan()]['amount'],
            'plans' => collect(AccountSubscription::PLANS)->map(fn (array $plan, string $key): array => [
                'label' => __($plan['label']),
                'amount' => AccountSubscription::priceWithVat($key),
                'renews' => $key === 'yearly' ? __('Then once a year') : __('Then every month'),
            ])->all(),
        ];
    }

    /** The terms go on the last step of every path. */
    private function isLastStep(string $name): bool
    {
        return $name === (self::takesPayment() ? 'payment' : 'accountant');
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

        $accountant = ($plan = $this->accountantPlan()) !== null ? AccountantService::priceWithVat($plan) : 0;

        return ['title' => __('Your plan'), 'empty' => null, 'plans' => collect(AccountSubscription::PLANS)->map(fn (array $plan, string $key): array => array_values(array_filter([
            ['label' => __('Plan'), 'value' => __($plan['label']), 'new' => false],
            ['label' => __('Today'), 'value' => Money::format(0), 'new' => true],
            ['label' => __('On :date', ['date' => $chargedOn]), 'value' => Money::format(AccountSubscription::priceWithVat($key) + $accountant), 'new' => false],
            $accountant > 0 ? ['label' => __('Accountant'), 'value' => __(':amount a month', ['amount' => Money::format($accountant)]), 'new' => true] : null,
            ['label' => __('Renews'), 'value' => $key === 'yearly' ? __('Every year') : __('Every month'), 'new' => false],
        ])))->all(), 'items' => []];
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
            'accountant' => $this->deduction()->path() === 'organised'
                ? [__('With organised accounting, you need a certified accountant'), __('They keep the accounts and sign the returns. You can have Contiu’s or bring your own.')]
                : [__('Do you want a certified accountant to handle your returns?'), __('Contiu tells you what to pay and when. If you would rather someone did it for you, add Contiu’s certified accountant to your subscription.')],
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
            eyebrow: __('Step :number of :total', ['number' => (int) array_search('profile', $names, true) + 1, 'total' => count($names)]),
            stepNames: $names,
        );
    }

    /**
     * Keeps what the "Conta criada" screen shows (EmailVerificationPrompt) for this session: the
     * tax card, what is agreed and the first steps. It is built from the answers here, the one
     * place that still has them all.
     */
    protected function afterRegister(): void
    {
        session()->put(EmailVerificationPrompt::ACCOUNT_CREATED_SESSION_KEY, [
            'user' => $this->form->getRecord()?->getKey(),
            ...$this->accountCreatedData(),
        ]);
    }

    /**
     * The "Conta criada" screen, as in the RegConcluido mocks: with the activity open, the tax card
     * from the profile step; on hold, a card with what Contiu already knows. Then what is agreed
     * (free days, first charge, accountant, what is still to confirm) and the first steps.
     *
     * @return array<string, mixed>
     */
    private function accountCreatedData(): array
    {
        $path = $this->deduction()->path();
        $waiting = $path === 'waiting';
        $name = trim((string) ($this->data['name'] ?? ''));
        $nif = (string) ($this->data['nif'] ?? '');
        $accountant = ($this->data['accountant'] ?? null) === 'contiu' ? $this->accountantPlan() : null;
        $accountantPrice = $accountant !== null ? AccountantService::priceWithVat($accountant) : 0;
        $plan = (string) ($this->data['plan'] ?? '');
        $chargedOn = $this->trialEndsOn();
        $profile = $this->profileData();

        $card = [
            ...Arr::only($profile, ['name', 'initials', 'logo', 'regime', 'role', 'facts']),
            ...match ($path) {
                'waiting' => [
                    'regime' => __('Activity not opened'),
                    'role' => $accountant !== null ? __('Account on hold · with a certified accountant') : __('Account on hold'),
                    'facts' => array_values(array_filter([
                        $nif !== '' ? ['label' => __('Tax ID'), 'value' => $nif] : null,
                        ['label' => __('Free until'), 'value' => $chargedOn],
                        ['label' => __('Taxes'), 'value' => __('When you open')],
                    ])),
                ],
                'organised' => ['regime' => __('Organised accounting')],
                default => [],
            },
        ];

        $charge = null;

        if (self::takesPayment() && isset(AccountSubscription::PLANS[$plan])) {
            $planPrice = AccountSubscription::priceWithVat($plan);
            $charge = [
                'label' => __('First charge'),
                'value' => __(':date · :amount', ['date' => $chargedOn, 'amount' => Money::format($planPrice + $accountantPrice)]),
                'note' => implode(' ', array_filter([
                    $accountant !== null
                        ? __(':plan plan :amount and accountant :accountant a month, VAT included.', ['plan' => __(AccountSubscription::PLANS[$plan]['label']), 'amount' => Money::format($planPrice), 'accountant' => Money::format($accountantPrice)])
                        : __(':plan plan :amount, VAT included.', ['plan' => __(AccountSubscription::PLANS[$plan]['label']), 'amount' => Money::format($planPrice)]),
                    $waiting ? __('Even if you have not opened your activity yet.') : null,
                    __('We email you 3 days before.'),
                ])),
            ];
        }

        $exemptionToConfirm = ! $waiting && ($profile['canConfirmExemption'] ?? false) && ! ($this->data['exemption_confirmed'] ?? false);

        return [
            'firstName' => (string) strtok($name, ' '),
            'email' => (string) ($this->data['email'] ?? ''),
            'waiting' => $waiting,
            'card' => $card,
            'lede' => $waiting
                ? __('The :days free days started today, with what you can already use. Your tax card is completed when you open your activity.', ['days' => config('contiu.trial_days')])
                : __('This is your tax card. The :days free days started today, with everything included. All that is left is to confirm your email.', ['days' => config('contiu.trial_days')]),
            'deal' => array_values(array_filter([
                [
                    'label' => __('Free days'),
                    'value' => __('Until :date', ['date' => $chargedOn]),
                    'note' => match (true) {
                        $waiting => __('Clients and suppliers, jobs and quotes, expenses.'),
                        $accountant !== null => __('You use everything, the accountant included. You paid nothing today.'),
                        default => __('You use everything. You paid nothing today.'),
                    },
                ],
                $charge,
                $accountant !== null ? [
                    'label' => __('Certified accountant'),
                    'value' => __('We introduce the accountant by email'),
                    'note' => $waiting
                        ? __('They help you open your activity and handle the returns from then on.')
                        : __('A certified accountant from a Contiu partner joins your account, with accountant access.'),
                ] : null,
                $exemptionToConfirm ? [
                    'label' => __('Tax profile'),
                    'value' => __('Social Security exemption to confirm'),
                    'note' => __('When you see it in Social Security Direct, mark “I have confirmed” in Tax settings.'),
                ] : null,
                self::takesPayment() ? [
                    'label' => __('Subscription invoice'),
                    'value' => __('In the name of :name · Tax ID :nif', ['name' => $name, 'nif' => $nif]),
                    'note' => __('It arrives by email with each charge.'),
                ] : null,
            ])),
            'next' => $waiting ? [
                'title' => __('When you open your activity with the Tax Authority'),
                'numbered' => true,
                'items' => [
                    [__('Open the activity'), $accountant !== null ? __('On the Tax Authority portal. Your Contiu accountant helps you fill in the declaration.') : __('On the Tax Authority portal.')],
                    [__('Press “I have opened my activity”'), __('In the Contiu dashboard, right at the start.')],
                    [__('Answer in two minutes'), __('Your situation and what you do. You see your tax card and start invoicing.')],
                ],
            ] : [
                'title' => __('First steps, once you are in'),
                'numbered' => false,
                'items' => [
                    [__('Add your first client'), __('Ready for the first invoice.')],
                    [__('Issue your first invoice'), __('With the withholding and the VAT already right.')],
                    [__('Say whether you have an account just for the activity'), __('Optional. It links the movements to the invoices.')],
                ],
            ],
            'side' => [
                'logo' => asset('brand/contiu/contiu-logo-white.svg'),
                'steps' => array_map(fn (string $step, int $position): array => [
                    'label' => __(self::STEP_LABELS[$step]),
                    'number' => $position + 1,
                    'state' => 'done',
                ], $this->stepNames(), array_keys($this->stepNames())),
                'panel' => ['title' => __('Your account'), 'empty' => null, 'items' => array_values(array_filter([
                    ['label' => __('Name'), 'value' => $name, 'new' => false],
                    ['label' => __('Email'), 'value' => (string) ($this->data['email'] ?? ''), 'new' => false],
                    isset(AccountSubscription::PLANS[$plan]) ? ['label' => __('Plan'), 'value' => __(AccountSubscription::PLANS[$plan]['label']), 'new' => false] : null,
                    $accountant !== null ? ['label' => __('Accountant'), 'value' => __(':amount a month', ['amount' => Money::format($accountantPrice)]), 'new' => false] : null,
                    ['label' => __('Free until'), 'value' => $chargedOn, 'new' => true],
                ]))],
                'foot' => __('You change the plan, the accountant or the payment details whenever you like, in Subscription.'),
            ],
        ];
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
        // The Stripe customer made inside the transaction, undone if the account is not saved.
        $stripeCustomer = null;

        try {
            return $this->createUserAndAccount($data, $stripeCustomer);
        } catch (Throwable $exception) {
            app(StartSignupSubscription::class)->undo($stripeCustomer);

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createUserAndAccount(#[SensitiveParameter] array $data, ?string &$stripeCustomer): User
    {
        return DB::transaction(function () use ($data, &$stripeCustomer): User {
            $user = new User;
            $user->forceFill([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'terms_accepted_at' => now(),
                'terms_version' => config('contiu.terms_version'),
                'marketing_opt_in_at' => ($data['marketing'] ?? false) ? now() : null,
                // The language picked on the sign-in pages, if any (ApplyGuestLanguage).
                'language' => Language::tryFrom((string) session(ApplyGuestLanguage::SESSION_KEY))?->value ?? Language::PortuguesePortugal->value,
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

            // Contiu's certified accountant: recorded for the partner to be introduced; with the
            // payment step, also its own Stripe subscription (startSubscription).
            if (($data['accountant'] ?? null) === 'contiu') {
                AccountantService::request($account, AccountantService::planFor($deduction->path() === 'organised' ? 'organised' : null));
            }

            if (self::takesPayment()) {
                $this->startSubscription($account, $data, $stripeCustomer);
            }

            return $user;
        });
    }

    /** @param array<string, mixed> $data */
    private function startSubscription(Account $account, array $data, ?string &$stripeCustomer): void
    {
        try {
            app(StartSignupSubscription::class)->handle($account, $data['plan'], $data['payment_method'], [
                'line1' => $data['billing_address'],
                'postal_code' => $data['billing_postal_code'],
                'city' => $data['billing_city'],
            ], accountant: $account->accountant_service);
        } catch (ApiErrorException $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'data.payment_method' => __('Stripe did not accept the card. Check the details or try another card.'),
            ]);
        } finally {
            $stripeCustomer = $account->stripe_id;
        }
    }

    /**
     * Before the card is confirmed: the whole sign-up must be valid (so a mistake never makes the
     * bank ask for authentication twice), then a SetupIntent for the Payment Element to confirm.
     * Null, with the errors on the form, when something is wrong.
     */
    public function prepareCardSetup(): ?string
    {
        abort_unless(self::takesPayment(), 404);

        try {
            $this->rateLimit(10);
        } catch (TooManyRequestsException $exception) {
            $this->addError('data.payment_method', __('Too many attempts. Try again in :seconds seconds.', ['seconds' => $exception->secondsUntilAvailable]));

            return null;
        }

        try {
            $this->form->validate();
        } catch (ValidationException $exception) {
            $errors = Arr::except($exception->errors(), ['data.payment_method']);

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
        }

        try {
            return app(StartSignupSubscription::class)->setupIntentSecret();
        } catch (ApiErrorException $exception) {
            report($exception);
            $this->addError('data.payment_method', __('Payments are unavailable right now. Try again in a few minutes.'));

            return null;
        }
    }
}
