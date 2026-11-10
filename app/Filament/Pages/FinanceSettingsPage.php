<?php

namespace App\Filament\Pages;

use App\Actions\ConfirmFiscalProfile;
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
use ToneGabes\Filament\Icons\Enums\Phosphor;
use UnitEnum;

/**
 * Fiscal regime and cash settings used by the forecast and the tax estimate.
 */
class FinanceSettingsPage extends Page
{
    protected static ?string $title = 'Definições fiscais';

    protected static ?string $slug = 'definicoes-fiscais';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::Sliders;

    protected static string|UnitEnum|null $navigationGroup = 'Definições';

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
        return 'Perfil fiscal confirmado e definições para a previsão de tesouraria e a estimativa de impostos.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('confirmFiscalProfile')
                ->label('Confirmar versão do perfil fiscal')
                ->icon(Phosphor::Plus)
                ->hidden(fn (): bool => CurrentAccount::readOnly())
                ->modalDescription('A versão fica no histórico. Se já houver uma versão com a mesma data, passa a valer a que foi confirmada por último.')
                ->modalSubmitActionLabel('Confirmar versão')
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
                    TextInput::make('legal_name')->label('Nome fiscal')->required()->maxLength(255),
                    TextInput::make('country_code')->label('País fiscal (código ISO)')->placeholder('PT')
                        ->required()->regex('/\A[A-Za-z]{2}\z/'),
                    TextInput::make('vat_number')->label('NIF / Número de IVA')->required()->maxLength(64),
                    TextInput::make('vat_regime')->label('Enquadramento em IVA declarado')->required()->maxLength(160),
                    DatePicker::make('activity_started_on')->label('Data de início de atividade'),
                    Select::make('has_dependent_employment')->label('Acumulação com trabalho dependente')
                        ->options(['yes' => 'Sim', 'no' => 'Não'])->placeholder('Por confirmar'),
                    Textarea::make('recognised_exemptions')->label('Isenções reconhecidas')->rows(2)->maxLength(2000)
                        ->helperText('Regista as isenções reconhecidas ou escreve «Nenhuma» se for o caso. Deixa em branco se estiver por confirmar.'),
                    Select::make('vat_exempt')->label('Isenção de IVA (art. 53.º) para a estimativa')
                        ->options(['yes' => 'Sim', 'no' => 'Não'])->placeholder('Por confirmar')->live(),
                    MoneyInput::make('vat_exemption_threshold')->label('Limite anual de isenção de IVA')
                        ->minValue(0.01)->rule('decimal:0,2')
                        ->visible(fn ($get): bool => $get('vat_exempt') === 'yes'),
                    Select::make('ss_contribution_exempt')->label('Isenção contributiva da Segurança Social para a estimativa')
                        ->options(['yes' => 'Sim', 'no' => 'Não'])->placeholder('Por confirmar'),
                    DatePicker::make('effective_from')->label('Em vigor desde')->required(),
                    Toggle::make('confirmed')->label('Confirmo que estes dados fiscais estão corretos')->accepted(),
                ])
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);

                    app(ConfirmFiscalProfile::class)->handle($actor, $data);

                    Notification::make()->title('Versão do perfil fiscal confirmada')->success()->send();
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
                Section::make('Atividade')
                    ->description('Quando e como começou a atividade: decide a Segurança Social do início e a redução do IRS dos primeiros anos.')
                    ->icon(Phosphor::Briefcase)
                    ->columns(2)
                    ->schema([
                        TaxSettingsFields::activityStartedOn()->columnSpanFull(),
                        ...TaxSettingsFields::activity(withPreviousYear: true),
                    ]),
                Section::make('IRS')
                    ->description('Regime simplificado: o rendimento tributável é o rendimento bruto vezes o coeficiente.')
                    ->icon(Phosphor::Receipt)
                    ->columns(2)
                    ->schema(TaxSettingsFields::irs(app(FinanceSettings::class))),
                Section::make('Segurança Social')
                    ->description('Contribuição sobre o rendimento relevante de cada trimestre.')
                    ->icon(Phosphor::Bank)
                    ->columns(2)
                    ->schema(TaxSettingsFields::socialSecurity()),
                Section::make('IVA')
                    ->icon(Phosphor::Percent)
                    ->schema([
                        Toggle::make('payments_exempt_by_default')
                            ->label('Recebimentos isentos por defeito')
                            ->helperText('Os recebimentos e as avenças novos vêm marcados como isentos de impostos (não contam para a estimativa). Podes desmarcar caso a caso.'),
                        TaxSettingsFields::whereToFind(
                            Toggle::make('vat_exempt')
                                ->label('Isento de IVA (art. 53.º)')
                                ->live(),
                            'No comprovativo de início ou de alteração de atividade, em «Enquadramento em IVA»: «Isenção artigo 53.º». No Portal das Finanças, em Declarações de Atividade.',
                        ),
                        MoneyInput::make('vat_exemption_threshold')
                            ->label('Limite anual de isenção de IVA (art. 53.º)')
                            ->minValue(0)
                            ->visible(fn ($get) => (bool) $get('vat_exempt'))
                            ->required(fn ($get) => (bool) $get('vat_exempt')),
                        // Stored as a fraction (0.23); typed as a percentage.
                        TaxSettingsFields::whereToFind(
                            TextInput::make('reverse_charge_vat_rate_percent')
                                ->label('Taxa de IVA na autoliquidação')
                                ->helperText('O IVA que liquidas nas compras de serviços a fornecedores com sede fora de Portugal. 23 % no continente.')
                                ->numeric()
                                ->step(1)
                                ->minValue(0)
                                ->maxValue(100)
                                ->suffix('%')
                                ->required(),
                            'A taxa normal da tua região: 23 % no continente, 16 % nos Açores, 22 % na Madeira (art. 18.º do CIVA).',
                        ),
                    ]),
                Section::make('Banco')
                    ->description('A conta da atividade é a conta bancária onde recebes e pagas o que é do trabalho. O extrato importado dá o saldo e confirma que o dinheiro registado chegou lá.')
                    ->icon(Phosphor::Wallet)
                    ->columnSpanFull()
                    ->schema([
                        Toggle::make('has_business_account')
                            ->label('Tenho uma conta bancária só para a atividade')
                            ->helperText('Desligado, o extrato bancário, a reconciliação e o saldo da conta deixam de aparecer. Os extratos já importados ficam guardados.')
                            ->live(),
                        Textarea::make('account_names')
                            ->label('Os teus nomes no extrato')
                            ->placeholder("ANA MARIA SILVA\nPoupança")
                            ->helperText('Quando passas dinheiro entre a conta da atividade e outra conta tua, o banco escreve um nome na descrição (o teu, ou o que deste à outra conta). Um por linha: esses movimentos ficam como "Transferência minha" e não contam como receita nem despesa.')
                            ->visible(fn ($get): bool => (bool) $get('has_business_account'))
                            ->rows(3),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Perfil fiscal')
                ->schema(function (): Schema|array {
                    $profile = FiscalProfile::effectiveOn(today());

                    return $profile
                        ? Schema::make()->record($profile)->components(static::fiscalProfileEntries())
                        : [TextEntry::make('emptyProfile')->hiddenLabel()->placeholder('Sem versão em vigor')];
                })
                ->description(function (): string {
                    $profile = FiscalProfile::effectiveOn(today());

                    return match (true) {
                        $profile === null => 'Por confirmar. A estimativa usa as definições abaixo até confirmares uma versão.',
                        $profile->confirmed_at === null => 'Versão em vigor, por confirmar.',
                        default => 'Confirmado a '.$profile->confirmed_at->format('d/m/Y').'.',
                    };
                })
                ->icon(fn (): Phosphor => FiscalProfile::effectiveOn(today())?->confirmed_at === null ? Phosphor::WarningCircle : Phosphor::CheckCircle)
                ->iconColor(fn (): string => FiscalProfile::effectiveOn(today())?->confirmed_at === null ? 'warning' : 'success')
                ->columns(3),
            Section::make('Histórico do perfil fiscal')
                ->collapsible()
                ->collapsed()
                ->schema([
                    RepeatableEntry::make('fiscalProfileHistory')
                        ->hiddenLabel()
                        ->placeholder('Sem versões confirmadas')
                        ->state(fn () => FiscalProfile::query()->inEffectiveOrder()->get())
                        ->schema([
                            ...static::fiscalProfileEntries(),
                            TextEntry::make('confirmed_at')->label('Confirmado em')->dateTime(),
                        ])
                        ->columns(3),
                ]),
            Callout::make('Base das estimativas históricas')
                ->description('A estimativa usa a data de início de atividade e os campos de IVA e Segurança Social confirmados na versão em vigor para o período. Sem dados confirmados? As estimativas continuam a usar as definições abaixo.')
                ->icon(Phosphor::Info),
            Callout::make('Estimativas — confirma os valores com o teu contabilista.')
                ->description('As regras de Segurança Social, IRS e IVA são simplificadas e servem apenas para planear.')
                ->icon(Phosphor::WarningCircle)
                ->status('warning'),
            Callout::make('Mudar estas definições recalcula a estimativa de impostos e a previsão de tesouraria.')
                ->icon(Phosphor::Info),
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')
                            ->label('Guardar')
                            ->submit('save')
                            // Settings have no policy: the read-only gate cannot hide the button.
                            ->disabled(fn () => CurrentAccount::readOnly())
                            ->tooltip(fn () => CurrentAccount::readOnly() ? 'A conta está só de leitura' : null),
                    ])->key('formActions'),
                ]),
        ]);
    }

    /** @return list<TextEntry> */
    private static function fiscalProfileEntries(): array
    {
        return [
            TextEntry::make('effective_from')->label('Em vigor desde')->date(),
            TextEntry::make('legal_name')->label('Nome fiscal'),
            TextEntry::make('country_code')->label('País fiscal'),
            TextEntry::make('vat_number')->label('NIF / Número de IVA'),
            TextEntry::make('vat_regime')->label('Enquadramento em IVA'),
            TextEntry::make('activity_started_on')->label('Data de início de atividade')->date()->placeholder('Por confirmar'),
            TextEntry::make('has_dependent_employment')->label('Acumulação com trabalho dependente')
                ->formatStateUsing(fn (bool $state): string => $state ? 'Sim' : 'Não')->placeholder('Por confirmar'),
            TextEntry::make('vat_exempt')->label('Isenção de IVA (art. 53.º) para a estimativa')
                ->formatStateUsing(fn (bool $state): string => $state ? 'Sim' : 'Não')->placeholder('Definições atuais'),
            TextEntry::make('vat_exemption_threshold')->label('Limite anual de isenção de IVA')
                ->formatStateUsing(fn (int $state): string => Money::format($state))->placeholder('Definições atuais'),
            TextEntry::make('ss_contribution_exempt')->label('Isenção contributiva para a estimativa')
                ->formatStateUsing(fn (bool $state): string => $state ? 'Sim' : 'Não')->placeholder('Definições atuais'),
            TextEntry::make('recognised_exemptions')->label('Isenções reconhecidas')->placeholder('Por confirmar')->columnSpanFull(),
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

        Notification::make()->title('Definições guardadas')->success()->send();
    }
}
