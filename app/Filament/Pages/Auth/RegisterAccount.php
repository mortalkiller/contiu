<?php

namespace App\Filament\Pages\Auth;

use App\Actions\CreateAccount;
use App\Enums\Language;
use App\Filament\Support\MoneyInput;
use App\Filament\Support\TaxSettingsFields;
use App\Models\Account;
use App\Models\User;
use App\Rules\PortugueseNif;
use App\Support\Money;
use App\Support\YearlyParameter;
use Filament\Actions\Action;
use Filament\Auth\Pages\Register;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;
use SensitiveParameter;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * Self-service sign-up: a wizard that asks everything a new account needs, then creates the user
 * and their account together (CreateAccount: owner, trial, fiscal settings, base categories).
 * Filament then sends the verification email; the panel stays shut until it is confirmed, and
 * the confirmation link opens the new account's dashboard.
 */
class RegisterAccount extends Register
{
    protected Width|string|null $maxContentWidth = Width::FiveExtraLarge;

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
                ->submitAction($this->getRegisterFormAction())
                ->alpineSubmitHandler('$wire.register()')
                ->contained(false)
                ->extraAttributes(['class' => 'contiu-signup-wizard']),
        ]);
    }

    /** The wizard carries its own buttons (and the submit on the last step). */
    public function getFormContentComponent(): Component
    {
        return EmbeddedSchema::make('form');
    }

    /** Outside a <form> (each step has its own), so it calls register() instead of submitting. */
    public function getRegisterFormAction(): Action
    {
        return parent::getRegisterFormAction()
            ->label(__('Create account'))
            ->submit(null)
            ->action('register');
    }

    /** @return array<int, Step> */
    protected function getSteps(): array
    {
        return [
            Step::make(__('Access'))
                ->icon(Phosphor::User)
                ->columns(2)
                ->schema([
                    $this->getNameFormComponent()
                        ->label(__('Your name')),
                    $this->getEmailFormComponent()
                        ->label(__('Email'))
                        ->helperText(__('We’ll send you a link to verify your email.')),
                    $this->getPasswordFormComponent()
                        ->label(__('Password'))
                        ->validationAttribute(__('password')),
                    $this->getPasswordConfirmationFormComponent()
                        ->label(__('Confirm password')),
                ]),

            Step::make(__('Activity'))
                ->icon(Phosphor::Briefcase)
                ->columns(2)
                ->schema([
                    TextInput::make('account_name')
                        ->label(__('Account name'))
                        ->helperText(__('Your professional or brand name. It appears in the account URL.'))
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),
                    TextInput::make('nif')
                        ->label(__('Tax ID'))
                        ->helperText(__('Your nine-digit Portuguese tax ID.'))
                        ->required()
                        ->rule(new PortugueseNif)
                        ->unique(Account::class, 'nif')
                        ->validationMessages(['unique' => __('An account already uses this tax ID.')])
                        ->extraInputAttributes(['inputmode' => 'numeric', 'autocomplete' => 'off']),
                    TaxSettingsFields::activityStartedOn()
                        ->required(),
                    ...TaxSettingsFields::activity(withPreviousYear: false, requireKind: true),
                ]),

            Step::make(__('Personal income tax'))
                ->icon(Phosphor::Receipt)
                ->columns(2)
                ->schema(TaxSettingsFields::irs()),

            Step::make(__('VAT'))
                ->icon(Phosphor::Percent)
                ->schema([
                    TaxSettingsFields::whereToFind(
                        Toggle::make('vat_exempt')
                            ->label(__('VAT exempt (Article 53)'))
                            ->helperText(__('New payments received are marked as exempt. You can change them individually.')),
                        __('On your activity registration or amendment receipt, look under “Enquadramento em IVA” for “Isenção artigo 53.º”. You can find it under “Declarações de Atividade” in the Portuguese Tax Portal.'),
                    ),
                    MoneyInput::make('vat_exemption_threshold')
                        ->label(__('Annual VAT exemption threshold'))
                        ->helperText(__('Annual invoicing threshold below which the exemption applies.'))
                        ->default(fn (): int => YearlyParameter::vatExemptionLimit((int) now()->year))
                        ->minValue(0)
                        ->visibleJs("!! \$get('vat_exempt')")
                        ->dehydrated(fn (Get $get): bool => (bool) $get('vat_exempt'))
                        ->validatedWhenNotDehydrated(false)
                        ->required(),
                ]),

            Step::make(__('Social Security'))
                ->icon(Phosphor::Bank)
                ->columns(2)
                ->schema(TaxSettingsFields::socialSecurity()),

            Step::make(__('Bank'))
                ->icon(Phosphor::Wallet)
                ->schema([
                    Toggle::make('has_business_account')
                        ->label(__('I have a dedicated business bank account'))
                        ->helperText(__('If so, you can import its statement. Contiu checks that recorded payments reached the account and shows the balance. Leave this off if you use the same account for everything. You can change this in Settings.'))
                        ->default(false),
                    Textarea::make('account_names')
                        ->label(__('Your names on the statement'))
                        ->placeholder("ANA MARIA SILVA\n".__('Savings'))
                        ->helperText(__('When you move money between your accounts, the bank puts a name in the description. Enter one per line. Contiu marks these transactions as “Transfer between my accounts” and excludes them from income and expenses. You can leave this blank and fill it in later.'))
                        ->visibleJs("!! \$get('has_business_account')")
                        ->dehydrated(fn (Get $get): bool => (bool) $get('has_business_account'))
                        ->validatedWhenNotDehydrated(false)
                        ->rows(3),
                ]),

            Step::make(__('Confirm'))
                ->icon(Phosphor::CheckCircle)
                ->columns(2)
                ->schema([
                    TextEntry::make('summary_account')
                        ->label(__('Account'))
                        ->state(fn (Get $get) => trim(__(':name · Tax ID :number', [
                            'name' => $get('account_name'),
                            'number' => $get('nif'),
                        ]), ' ·')),
                    TextEntry::make('summary_email')
                        ->label(__('Email'))
                        ->state(fn (Get $get) => $get('email') ?: '—'),
                    TextEntry::make('summary_activity')
                        ->label(__('Activity'))
                        ->listWithLineBreaks()
                        ->state(fn (Get $get) => self::activitySummary($get)),
                    TextEntry::make('summary_irs')
                        ->label(__('Personal income tax'))
                        ->state(fn (Get $get) => self::irsSummary($get)),
                    TextEntry::make('summary_taxes')
                        ->label(__('VAT'))
                        ->state(fn (Get $get) => self::vatSummary($get)),
                    TextEntry::make('summary_social_security')
                        ->label(__('Social Security'))
                        ->state(fn (Get $get) => self::socialSecuritySummary($get)),
                    Checkbox::make('terms')
                        ->label(new HtmlString(__('I have read and accept the :terms and the :privacy.', [
                            'terms' => '<a href="'.e(url('/termos')).'" target="_blank" class="font-medium underline">'.e(__('Terms of Service')).'</a>',
                            'privacy' => '<a href="'.e(url('/privacidade')).'" target="_blank" class="font-medium underline">'.e(__('Privacy Policy')).'</a>',
                        ])))
                        ->accepted()
                        ->validationMessages(['accepted' => __('You must accept the Terms of Service and Privacy Policy.')])
                        // Recorded on the user (when and which version), not stored as typed.
                        ->dehydrated(false)
                        ->columnSpanFull(),
                ]),
        ];
    }

    /**
     * The user and the account are created together: if the account fails, no user is left
     * behind without one.
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

            $vatExempt = (bool) ($data['vat_exempt'] ?? false);

            app(CreateAccount::class)->handle($user, [
                'name' => $data['account_name'],
                'nif' => $data['nif'],
                ...TaxSettingsFields::toSettings($data),
                'vat_exempt' => $vatExempt,
                // Without the exemption the limit is not asked, so the default stays.
                ...($vatExempt ? ['vat_exemption_threshold' => (int) $data['vat_exemption_threshold']] : []),
                'has_business_account' => $hasBusinessAccount = (bool) ($data['has_business_account'] ?? false),
                'account_names' => $hasBusinessAccount ? ($data['account_names'] ?? null) : null,
            ]);

            return $user;
        });
    }

    private static function vatSummary(Get $get): string
    {
        return $get('vat_exempt')
            ? __('VAT exempt up to :amount', [
                'amount' => Money::format(Money::toCents($get('vat_exemption_threshold')), locale: app()->getLocale()),
            ])
            : __('VAT applies');
    }

    /** @return array<int, string> */
    private static function activitySummary(Get $get): array
    {
        $date = fn (?string $value) => filled($value) ? Carbon::parse($value)->format(Language::resolve(app()->getLocale())->dateFormat()) : '—';
        $restart = $get('activity_start_kind') === 'restart';
        $startedOn = $date($get('activity_started_on'));
        $year = now()->year;

        return array_values(array_filter([
            $restart
                ? __('Activity restarted on :date (previous activity ended on :previousDate)', [
                    'date' => $startedOn,
                    'previousDate' => $date($get('previous_activity_ended_on')),
                ])
                : __('Activity first started on :date', ['date' => $startedOn]),
            filled($get('ss_enrolled_from'))
                ? __('Registered with Social Security from :date', ['date' => $date($get('ss_enrolled_from'))])
                : null,
            $get('dependent_income_current_year')
                ? __('With salary or pension in :year', ['year' => $year])
                : __('Without salary or pension in :year', ['year' => $year]),
        ]));
    }

    private static function irsSummary(Get $get): string
    {
        $coefficient = Number::format((float) $get('irs_coefficient'), maxPrecision: 2, locale: app()->getLocale());

        return $get('irs_jovem')
            ? __('Coefficient :coefficient · IRS Jovem, year no. :yearNumber in :year', [
                'coefficient' => $coefficient,
                'yearNumber' => $get('irs_jovem_year_number'),
                'year' => now()->year,
            ])
            : __('Coefficient :coefficient · without IRS Jovem', ['coefficient' => $coefficient]);
    }

    private static function socialSecuritySummary(Get $get): string
    {
        $percent = fn (float $value) => Number::format(round($value, 2), maxPrecision: 2, locale: app()->getLocale());
        $replacements = [
            'rate' => $percent((float) $get('ss_rate') * 100),
            // Art. 162.º CRC: 20 % of sales of goods, restaurants and hotels, 70 % of services.
            'share' => $percent(round((float) $get('irs_coefficient'), 2) === 0.15 ? 20.0 : 70.0),
        ];

        return $get('ss_contribution_exempt')
            ? __(':rate% on :share% of income · exempt from contributions (Article 157)', $replacements)
            : __(':rate% on :share% of income', $replacements);
    }
}
