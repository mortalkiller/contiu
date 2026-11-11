<?php

namespace App\Filament\Pages;

use App\Actions\ConfirmFiscalProfile;
use App\Enums\Language;
use App\Filament\Support\MoneyInput;
use App\Filament\Support\TaxSettingsFields;
use App\Models\FiscalProfile;
use App\Models\User;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * Fiscal regime and cash settings used by the forecast and the tax estimate.
 */
class FinanceSettingsPage extends Page
{
    protected static ?string $slug = 'definicoes-fiscais';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::Sliders;

    protected static ?int $navigationSort = 5;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $settings = app(FinanceSettings::class);

        $this->form->fill([
            ...TaxSettingsFields::fill($settings),
            'reverse_charge_vat_rate_percent' => round($settings->reverseChargeVatRate() * 100, 2),
            'vat_exempt' => $settings->vatExempt(),
            'vat_exemption_threshold' => $settings->vatExemptionThreshold(),
            'payments_exempt_by_default' => $settings->paymentsExemptByDefault(),
            'has_business_account' => $settings->hasBusinessAccount(),
            'account_names' => implode("\n", $settings->accountNames()),
        ]);
    }

    public function getSubheading(): string
    {
        return __('Confirmed tax profile and settings for the cash forecast and tax estimate.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('confirmFiscalProfile')
                ->label(__('Confirm tax profile version'))
                ->icon(Phosphor::Plus)
                ->hidden(fn (): bool => CurrentAccount::readOnly())
                ->modalDescription(__('The version is kept in the history. If a version already exists for the same date, the most recently confirmed version applies.'))
                ->modalSubmitActionLabel(__('Confirm version'))
                ->fillForm(function (): array {
                    $profile = FiscalProfile::effectiveOn(today());

                    return [
                        ...($profile?->only(['legal_name', 'country_code', 'vat_number', 'vat_regime', 'recognised_exemptions']) ?? []),
                        'activity_started_on' => $profile?->activity_started_on?->toDateString(),
                        'has_dependent_employment' => self::confirmedChoice($profile?->has_dependent_employment),
                        'vat_exempt' => self::confirmedChoice($profile?->vat_exempt),
                        'vat_exemption_threshold' => $profile?->vat_exemption_threshold,
                        'ss_contribution_exempt' => self::confirmedChoice($profile?->ss_contribution_exempt),
                        'effective_from' => today()->toDateString(),
                    ];
                })
                ->schema([
                    TextInput::make('legal_name')->label(__('Registered tax name'))->required()->maxLength(255),
                    TextInput::make('country_code')->label(__('Tax country (ISO code)'))->placeholder('PT')
                        ->required()->regex('/\A[A-Za-z]{2}\z/'),
                    TextInput::make('vat_number')->label(__('Tax ID / VAT number'))->required()->maxLength(64),
                    TextInput::make('vat_regime')->label(__('Declared VAT status'))->required()->maxLength(160),
                    DatePicker::make('activity_started_on')->label(__('Activity start date')),
                    Select::make('has_dependent_employment')->label(__('Concurrent employment'))
                        ->options(['yes' => __('Yes'), 'no' => __('No')])->placeholder(__('Awaiting confirmation')),
                    Textarea::make('recognised_exemptions')->label(__('Recognized exemptions'))->rows(2)->maxLength(2000)
                        ->helperText(__('Enter any recognized exemptions or type “None” if applicable. Leave blank if unconfirmed.')),
                    Select::make('vat_exempt')->label(__('VAT exemption (Article 53) for the estimate'))
                        ->options(['yes' => __('Yes'), 'no' => __('No')])->placeholder(__('Awaiting confirmation'))->live(),
                    MoneyInput::make('vat_exemption_threshold')->label(__('Annual VAT exemption threshold'))
                        ->minValue(0.01)->rule('decimal:0,2')
                        ->visible(fn ($get): bool => $get('vat_exempt') === 'yes'),
                    Select::make('ss_contribution_exempt')->label(__('Social Security contribution exemption for the estimate'))
                        ->options(['yes' => __('Yes'), 'no' => __('No')])->placeholder(__('Awaiting confirmation')),
                    DatePicker::make('effective_from')->label(__('Effective from'))->required(),
                    Toggle::make('confirmed')->label(__('I confirm that these tax details are correct'))->accepted(),
                ])
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);

                    app(ConfirmFiscalProfile::class)->handle($actor, $data);

                    Notification::make()->title(__('Tax profile version confirmed'))->success()->send();
                }),
        ];
    }

    private static function confirmedChoice(?bool $state): ?string
    {
        return match ($state) {
            true => 'yes',
            false => 'no',
            null => null,
        };
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(['lg' => 2])
            ->components([
                Section::make(__('Activity'))
                    ->description(__('When and how you started trading determines initial Social Security contributions and the income tax reduction for the first years.'))
                    ->icon(Phosphor::Briefcase)
                    ->columns(2)
                    ->schema([
                        TaxSettingsFields::activityStartedOn()->columnSpanFull(),
                        ...TaxSettingsFields::activity(withPreviousYear: true),
                    ]),
                Section::make(__('Personal income tax'))
                    ->description(__('Under the simplified regime, taxable income is gross income multiplied by the coefficient.'))
                    ->icon(Phosphor::Receipt)
                    ->columns(2)
                    ->schema(TaxSettingsFields::irs(app(FinanceSettings::class))),
                Section::make(__('Social Security'))
                    ->description(__('Contribution based on each quarter’s relevant income.'))
                    ->icon(Phosphor::Bank)
                    ->columns(2)
                    ->schema(TaxSettingsFields::socialSecurity()),
                Section::make(__('VAT'))
                    ->icon(Phosphor::Percent)
                    ->schema([
                        Toggle::make('payments_exempt_by_default')
                            ->label(__('Payments received are exempt by default'))
                            ->helperText(__('New payments received and retainers are marked as tax exempt and excluded from the estimate. You can change them individually.')),
                        TaxSettingsFields::whereToFind(
                            Toggle::make('vat_exempt')
                                ->label(__('VAT exempt (Article 53)'))
                                ->live(),
                            __('On your activity registration or amendment receipt, look under “Enquadramento em IVA” for “Isenção artigo 53.º”. You can find it under “Declarações de Atividade” in the Portuguese Tax Portal.'),
                        ),
                        MoneyInput::make('vat_exemption_threshold')
                            ->label(__('Annual VAT exemption threshold (Article 53)'))
                            ->minValue(0)
                            ->visible(fn ($get) => (bool) $get('vat_exempt'))
                            ->required(fn ($get) => (bool) $get('vat_exempt')),
                        // Stored as a fraction (0.23); typed as a percentage.
                        TaxSettingsFields::whereToFind(
                            TextInput::make('reverse_charge_vat_rate_percent')
                                ->label(__('Reverse-charge VAT rate'))
                                ->helperText(__('VAT you account for when buying services from suppliers outside Portugal. The mainland rate is 23%.'))
                                ->numeric()
                                ->step(1)
                                ->minValue(0)
                                ->maxValue(100)
                                ->suffix('%')
                                ->required(),
                            __('Your region’s standard rate: 23% on the mainland, 16% in the Azores and 22% in Madeira (Article 18 of the Portuguese VAT Code).'),
                        ),
                    ]),
                Section::make(__('Bank'))
                    ->description(__('Your business account is the bank account you use for work payments. The imported statement provides the balance and verifies recorded payments.'))
                    ->icon(Phosphor::Wallet)
                    ->columnSpanFull()
                    ->schema([
                        Toggle::make('has_business_account')
                            ->label(__('I have a dedicated business bank account'))
                            ->helperText(__('Turning this off hides the bank statement, reconciliation and account balance. Previously imported statements are retained.'))
                            ->live(),
                        Textarea::make('account_names')
                            ->label(__('Your names on the statement'))
                            ->placeholder(__("ANA MARIA SILVA\nSavings"))
                            ->helperText(__('When you move money between your business account and another account you own, the bank puts your name or the other account’s name in the description. Enter one name per line. These transactions are marked “Transfer between my accounts” and excluded from income and expenses.'))
                            ->visible(fn ($get): bool => (bool) $get('has_business_account'))
                            ->rows(3),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Tax profile'))
                ->schema(function (): Schema|array {
                    $profile = FiscalProfile::effectiveOn(today());

                    return $profile
                        ? Schema::make()->record($profile)->components(static::fiscalProfileEntries())
                        : [TextEntry::make('emptyProfile')->hiddenLabel()->placeholder(__('No effective version'))];
                })
                ->description(function (): string {
                    $profile = FiscalProfile::effectiveOn(today());

                    return match (true) {
                        $profile === null => __('Awaiting confirmation. The estimate uses the settings below until you confirm a version.'),
                        $profile->confirmed_at === null => __('Effective version awaiting confirmation.'),
                        default => __('Confirmed on :date.', ['date' => $profile->confirmed_at->format(Language::resolve(app()->getLocale())->dateFormat())]),
                    };
                })
                ->icon(fn (): Phosphor => FiscalProfile::effectiveOn(today())?->confirmed_at === null ? Phosphor::WarningCircle : Phosphor::CheckCircle)
                ->iconColor(fn (): string => FiscalProfile::effectiveOn(today())?->confirmed_at === null ? 'warning' : 'success')
                ->columns(3),
            Section::make(__('Tax profile history'))
                ->collapsible()
                ->collapsed()
                ->schema([
                    RepeatableEntry::make('fiscalProfileHistory')
                        ->hiddenLabel()
                        ->placeholder(__('No confirmed versions'))
                        ->state(fn () => FiscalProfile::query()->inEffectiveOrder()->get())
                        ->schema([
                            ...static::fiscalProfileEntries(),
                            TextEntry::make('confirmed_at')->label(__('Confirmed on'))->dateTime(),
                        ])
                        ->columns(3),
                ]),
            Callout::make(__('Basis for historical estimates'))
                ->description(__('The estimate uses the activity start date and the VAT and Social Security details confirmed in the version effective for the period. The estimate uses the settings below for unconfirmed details.'))
                ->icon(Phosphor::Info),
            Callout::make(__('Estimates — check the amounts with your accountant.'))
                ->description(__('The Social Security, personal income tax and VAT rules are simplified for planning purposes.'))
                ->icon(Phosphor::WarningCircle)
                ->status('warning'),
            Callout::make(__('Changing these settings recalculates the tax estimate and cash forecast.'))
                ->icon(Phosphor::Info),
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')
                            ->label(__('Save'))
                            ->submit('save')
                            // Settings have no policy: the read-only gate cannot hide the button.
                            ->disabled(fn () => CurrentAccount::readOnly())
                            ->tooltip(fn () => CurrentAccount::readOnly() ? __('The account is read-only') : null),
                    ])->key('formActions'),
                ]),
        ]);
    }

    /** @return list<TextEntry> */
    private static function fiscalProfileEntries(): array
    {
        return [
            TextEntry::make('effective_from')->label(__('Effective from'))->date(),
            TextEntry::make('legal_name')->label(__('Registered tax name')),
            TextEntry::make('country_code')->label(__('Tax country')),
            TextEntry::make('vat_number')->label(__('Tax ID / VAT number')),
            TextEntry::make('vat_regime')->label(__('VAT status')),
            TextEntry::make('activity_started_on')->label(__('Activity start date'))->date()->placeholder(__('Awaiting confirmation')),
            TextEntry::make('has_dependent_employment')->label(__('Concurrent employment'))
                ->formatStateUsing(fn (bool $state): string => $state ? __('Yes') : __('No'))->placeholder(__('Awaiting confirmation')),
            TextEntry::make('vat_exempt')->label(__('VAT exemption (Article 53) for the estimate'))
                ->formatStateUsing(fn (bool $state): string => $state ? __('Yes') : __('No'))->placeholder(__('Current settings')),
            TextEntry::make('vat_exemption_threshold')->label(__('Annual VAT exemption threshold'))
                ->formatStateUsing(fn (int $state): string => Money::format($state, locale: app()->getLocale()))->placeholder(__('Current settings')),
            TextEntry::make('ss_contribution_exempt')->label(__('Contribution exemption for the estimate'))
                ->formatStateUsing(fn (bool $state): string => $state ? __('Yes') : __('No'))->placeholder(__('Current settings')),
            TextEntry::make('recognised_exemptions')->label(__('Recognized exemptions'))->placeholder(__('Awaiting confirmation'))->columnSpanFull(),
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $settings = app(FinanceSettings::class);

        $settings->set([
            ...TaxSettingsFields::toSettings($data, $settings->dependentIncomeYears()),
            'reverse_charge_vat_rate' => round((float) $data['reverse_charge_vat_rate_percent'] / 100, 4),
            'vat_exempt' => (bool) ($data['vat_exempt'] ?? false),
            'vat_exemption_threshold' => (int) ($data['vat_exemption_threshold'] ?? 0),
            'payments_exempt_by_default' => (bool) ($data['payments_exempt_by_default'] ?? false),
            'has_business_account' => $hasBusinessAccount = (bool) ($data['has_business_account'] ?? false),
            // Hidden while off: kept as they were, for when it is turned back on.
            'account_names' => $hasBusinessAccount
                ? (implode("\n", array_filter(array_map('trim', preg_split('/\R/', (string) ($data['account_names'] ?? '')) ?: []))) ?: null)
                : $settings->all()['account_names'],
        ]);

        Notification::make()->title(__('Settings saved'))->success()->send();
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return __('Tax settings');
    }

    #[Unexposed]
    public static function getNavigationGroup(): string
    {
        return __('Settings');
    }

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('Tax settings');
    }
}
