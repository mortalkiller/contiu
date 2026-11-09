<?php

namespace App\Filament\Pages;

use App\Filament\Support\MoneyInput;
use App\Filament\Support\TaxSettingsFields;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
        return 'ENI em regime simplificado. Estes valores alimentam a previsão de tesouraria e a estimativa de impostos.';
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
                        Toggle::make('vat_exempt')
                            ->label('Isento de IVA (art. 53.º)')
                            ->live(),
                        MoneyInput::make('vat_exemption_threshold')
                            ->label('Limite anual de isenção de IVA (art. 53.º)')
                            ->minValue(0)
                            ->visible(fn ($get) => (bool) $get('vat_exempt'))
                            ->required(fn ($get) => (bool) $get('vat_exempt')),
                        // Stored as a fraction (0.23); typed as a percentage.
                        TextInput::make('reverse_charge_vat_rate_percent')
                            ->label('Taxa de IVA na autoliquidação')
                            ->helperText('O IVA que liquidas nas compras de serviços a fornecedores com sede fora de Portugal. 23 % no continente.')
                            ->numeric()
                            ->step(1)
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%')
                            ->required(),
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
            Callout::make('Estimativas — confirma os valores com o teu contabilista.')
                ->description('As regras de Segurança Social, IRS e IVA são simplificadas e servem apenas para planear.')
                ->icon(Phosphor::WarningCircle)
                ->status('warning'),
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
