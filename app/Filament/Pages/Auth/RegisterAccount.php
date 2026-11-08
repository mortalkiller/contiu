<?php

namespace App\Filament\Pages\Auth;

use App\Actions\CreateAccount;
use App\Filament\Support\MoneyInput;
use App\Models\Account;
use App\Models\User;
use App\Rules\PortugueseNif;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Auth\Pages\Register;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
                    DatePicker::make('activity_started_on')
                        ->label('Início de atividade')
                        ->helperText('A data em que abriste atividade nas Finanças.')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn ($state, Set $set) => $set('ss_first_year_exempt', self::inFirstYear($state))),
                ]),

            Step::make('Impostos')
                ->icon(Phosphor::Receipt)
                ->schema([
                    Toggle::make('irs_exempt')
                        ->label('Isento de IRS')
                        ->helperText('Liga se, para já, não pagas IRS. Sem isenção, a estimativa usa o coeficiente de 75%.'),
                    Toggle::make('vat_exempt')
                        ->label('Isento de IVA (art. 53.º)')
                        ->helperText('Os recebimentos novos vêm marcados como isentos; podes mudar caso a caso.')
                        ->live(),
                    MoneyInput::make('vat_exemption_threshold')
                        ->label('Limite anual de isenção de IVA')
                        ->helperText('Faturação anual até à qual a isenção se mantém.')
                        ->default(1500000)
                        ->visible(fn (Get $get) => (bool) $get('vat_exempt'))
                        ->required(fn (Get $get) => (bool) $get('vat_exempt')),
                ]),

            Step::make('Segurança Social')
                ->icon(Phosphor::Bank)
                ->columns(2)
                ->schema([
                    // Stored as fractions (0.214); typed as percentages, like the fiscal settings page.
                    TextInput::make('ss_rate_percent')
                        ->label('Taxa contributiva')
                        ->helperText('A taxa dos trabalhadores independentes.')
                        ->numeric()
                        ->step(0.1)
                        ->minValue(0)
                        ->maxValue(100)
                        ->suffix('%')
                        ->default(21.4)
                        ->required(),
                    TextInput::make('ss_relevant_income_share_percent')
                        ->label('Rendimento relevante')
                        ->helperText('70% para prestação de serviços.')
                        ->numeric()
                        ->step(1)
                        ->minValue(0)
                        ->maxValue(100)
                        ->suffix('%')
                        ->default(70)
                        ->required(),
                    Toggle::make('ss_first_year_exempt')
                        ->label('Isento nos primeiros 12 meses')
                        ->helperText('Ligado por defeito quando a atividade começou há menos de 12 meses.')
                        ->columnSpanFull(),
                ]),

            Step::make('Banco')
                ->icon(Phosphor::Wallet)
                ->schema([
                    Textarea::make('account_names')
                        ->label('Nomes das tuas contas, como aparecem no banco')
                        ->placeholder("ANA MARIA SILVA\nConta pessoal")
                        ->helperText('Um por linha, para o extrato reconhecer transferências entre as tuas contas. Podes deixar em branco e preencher depois.')
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
                        ->label('Início de atividade')
                        ->state(fn (Get $get) => filled($get('activity_started_on')) ? Carbon::parse($get('activity_started_on'))->format('d/m/Y') : '—'),
                    TextEntry::make('summary_taxes')
                        ->label('IRS e IVA')
                        ->state(fn (Get $get) => self::taxSummary($get)),
                    TextEntry::make('summary_social_security')
                        ->label('Segurança Social')
                        ->columnSpanFull()
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
                'activity_started_on' => $data['activity_started_on'],
                'irs_exempt' => (bool) ($data['irs_exempt'] ?? false),
                'vat_exempt' => $vatExempt,
                // Without the exemption the limit is not asked, so the default stays.
                ...($vatExempt ? ['vat_exemption_threshold' => (int) $data['vat_exemption_threshold']] : []),
                'ss_rate' => round((float) $data['ss_rate_percent'] / 100, 4),
                'ss_relevant_income_share' => round((float) $data['ss_relevant_income_share_percent'] / 100, 4),
                'ss_first_year_exempt' => (bool) ($data['ss_first_year_exempt'] ?? false),
                'account_names' => $data['account_names'] ?? null,
            ]);

            return $user;
        });
    }

    /** Social Security exempts the first 12 months of activity (and an activity about to start). */
    private static function inFirstYear(mixed $startedOn): bool
    {
        return filled($startedOn) && Carbon::parse($startedOn)->greaterThanOrEqualTo(today()->subYear());
    }

    private static function taxSummary(Get $get): string
    {
        $irs = $get('irs_exempt') ? 'Isento de IRS' : 'Sem isenção de IRS';
        $vat = $get('vat_exempt')
            ? 'isento de IVA até '.Money::format(Money::toCents($get('vat_exemption_threshold')))
            : 'com IVA';

        return "{$irs}; {$vat}";
    }

    private static function socialSecuritySummary(Get $get): string
    {
        $percent = fn ($value) => str_replace('.', ',', (string) round((float) $value, 2)).' %';
        $summary = $percent($get('ss_rate_percent')).' sobre '.$percent($get('ss_relevant_income_share_percent')).' do rendimento';

        return $get('ss_first_year_exempt') ? "{$summary}; isento nos primeiros 12 meses" : $summary;
    }
}
