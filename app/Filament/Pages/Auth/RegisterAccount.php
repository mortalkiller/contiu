<?php

namespace App\Filament\Pages\Auth;

use App\Actions\CreateAccount;
use App\Filament\Support\MoneyInput;
use App\Filament\Support\TaxSettingsFields;
use App\Models\Account;
use App\Models\User;
use App\Rules\PortugueseNif;
use App\Support\Money;
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
        return 'Criar conta';
    }

    public function getHeading(): string|Htmlable|null
    {
        return 'Criar conta na Contiu';
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
            ->label('Criar conta')
            ->submit(null)
            ->action('register');
    }

    /** @return array<int, Step> */
    protected function getSteps(): array
    {
        return [
            Step::make('Acesso')
                ->icon(Phosphor::User)
                ->columns(2)
                ->schema([
                    $this->getNameFormComponent()
                        ->label('O teu nome'),
                    $this->getEmailFormComponent()
                        ->label('Email')
                        ->helperText('Enviamos-te um link para confirmares o email.'),
                    $this->getPasswordFormComponent()
                        ->label('Palavra-passe')
                        ->validationAttribute('palavra-passe'),
                    $this->getPasswordConfirmationFormComponent()
                        ->label('Confirmar palavra-passe'),
                ]),

            Step::make('Atividade')
                ->icon(Phosphor::Briefcase)
                ->columns(2)
                ->schema([
                    TextInput::make('account_name')
                        ->label('Nome da conta')
                        ->helperText('O teu nome profissional ou da marca; aparece no endereço da conta.')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),
                    TextInput::make('nif')
                        ->label('NIF')
                        ->helperText('O teu número de contribuinte, com 9 dígitos.')
                        ->required()
                        ->rule(new PortugueseNif)
                        ->unique(Account::class, 'nif')
                        ->validationMessages(['unique' => 'Já existe uma conta com este NIF.'])
                        ->extraInputAttributes(['inputmode' => 'numeric', 'autocomplete' => 'off']),
                    TaxSettingsFields::activityStartedOn()
                        ->required(),
                    ...TaxSettingsFields::activity(withPreviousYear: false, requireKind: true),
                ]),

            Step::make('IRS')
                ->icon(Phosphor::Receipt)
                ->columns(2)
                ->schema(TaxSettingsFields::irs()),

            Step::make('IVA')
                ->icon(Phosphor::Percent)
                ->schema([
                    TaxSettingsFields::whereToFind(
                        Toggle::make('vat_exempt')
                            ->label('Isento de IVA (art. 53.º)')
                            ->helperText('Os recebimentos novos vêm marcados como isentos; podes mudar caso a caso.')
                            ->live(),
                        'No comprovativo de início ou de alteração de atividade, em «Enquadramento em IVA»: «Isenção artigo 53.º». No Portal das Finanças, em Declarações de Atividade.',
                    ),
                    MoneyInput::make('vat_exemption_threshold')
                        ->label('Limite anual de isenção de IVA')
                        ->helperText('Faturação anual até à qual a isenção se mantém.')
                        ->default(1500000)
                        ->minValue(0)
                        ->visible(fn (Get $get) => (bool) $get('vat_exempt'))
                        ->required(fn (Get $get) => (bool) $get('vat_exempt')),
                ]),

            Step::make('Segurança Social')
                ->icon(Phosphor::Bank)
                ->columns(2)
                ->schema(TaxSettingsFields::socialSecurity()),

            Step::make('Banco')
                ->icon(Phosphor::Wallet)
                ->schema([
                    Toggle::make('has_business_account')
                        ->label('Tenho uma conta bancária só para a atividade')
                        ->helperText('Se sim, podes importar o extrato dessa conta: a Contiu confirma que o dinheiro que registas chegou lá e mostra o saldo. Se usas a mesma conta para tudo, deixa desligado. Podes mudar nas Definições.')
                        ->default(false)
                        ->live(),
                    Textarea::make('account_names')
                        ->label('Os teus nomes no extrato')
                        ->placeholder("ANA MARIA SILVA\nPoupança")
                        ->helperText('Quando passas dinheiro entre esta conta e outra conta tua, o banco escreve um nome na descrição. Um por linha: esses movimentos ficam como "Transferência minha" e não contam como receita nem despesa. Podes deixar em branco e preencher depois.')
                        ->visible(fn (Get $get): bool => (bool) $get('has_business_account'))
                        ->rows(3),
                ]),

            Step::make('Confirmar')
                ->icon(Phosphor::CheckCircle)
                ->columns(2)
                ->schema([
                    TextEntry::make('summary_account')
                        ->label('Conta')
                        ->state(fn (Get $get) => trim($get('account_name').' · NIF '.$get('nif'), ' ·')),
                    TextEntry::make('summary_email')
                        ->label('Email')
                        ->state(fn (Get $get) => $get('email') ?: '—'),
                    TextEntry::make('summary_activity')
                        ->label('Atividade')
                        ->listWithLineBreaks()
                        ->state(fn (Get $get) => self::activitySummary($get)),
                    TextEntry::make('summary_irs')
                        ->label('IRS')
                        ->state(fn (Get $get) => self::irsSummary($get)),
                    TextEntry::make('summary_taxes')
                        ->label('IVA')
                        ->state(fn (Get $get) => self::vatSummary($get)),
                    TextEntry::make('summary_social_security')
                        ->label('Segurança Social')
                        ->state(fn (Get $get) => self::socialSecuritySummary($get)),
                    Checkbox::make('terms')
                        ->label(new HtmlString(
                            'Li e aceito os <a href="'.e(url('/termos')).'" target="_blank" class="font-medium underline">Termos de Serviço</a>'
                            .' e a <a href="'.e(url('/privacidade')).'" target="_blank" class="font-medium underline">Política de Privacidade</a>.'
                        ))
                        ->accepted()
                        ->validationMessages(['accepted' => 'Tens de aceitar os Termos de Serviço e a Política de Privacidade.'])
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
            ? 'Isento de IVA até '.Money::format(Money::toCents($get('vat_exemption_threshold')))
            : 'Com IVA';
    }

    /** @return array<int, string> */
    private static function activitySummary(Get $get): array
    {
        $date = fn (?string $value) => filled($value) ? Carbon::parse($value)->format('d/m/Y') : '—';
        $restart = $get('activity_start_kind') === 'restart';
        $year = now()->year;

        return array_values(array_filter([
            ($restart ? 'Reinício a ' : 'Primeiro início a ').$date($get('activity_started_on'))
                .($restart ? ' (cessação anterior a '.$date($get('previous_activity_ended_on')).')' : ''),
            filled($get('ss_enrolled_from')) ? 'Enquadrado na Segurança Social a partir de '.$date($get('ss_enrolled_from')) : null,
            $get('dependent_income_current_year') ? "Com salário ou pensão em {$year}" : "Sem salário nem pensão em {$year}",
        ]));
    }

    private static function irsSummary(Get $get): string
    {
        return 'Coeficiente '.str_replace('.', ',', (string) (float) $get('irs_coefficient'))
            .($get('irs_jovem') ? ' · IRS Jovem, ano n.º '.$get('irs_jovem_year_number').' em '.now()->year : ' · sem IRS Jovem');
    }

    private static function socialSecuritySummary(Get $get): string
    {
        $percent = fn ($value) => str_replace('.', ',', (string) round((float) $value, 2)).' %';

        return $percent((float) $get('ss_rate') * 100).' sobre '.$percent($get('ss_relevant_income_share_percent')).' do rendimento'
            .($get('ss_contribution_exempt') ? ' · isento de contribuições (art. 157.º)' : '');
    }
}
