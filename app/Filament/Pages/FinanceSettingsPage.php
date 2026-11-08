<?php

namespace App\Filament\Pages;

use App\Filament\Support\MoneyInput;
use App\Support\FinanceSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
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
            'activity_started_on' => $settings->activityStartedOn()?->toDateString(),
            'ss_first_year_exempt' => $settings->ssFirstYearExempt(),
            'ss_rate_percent' => round($settings->ssRate() * 100, 2),
            'ss_relevant_income_share_percent' => round($settings->ssRelevantIncomeShare() * 100, 2),
            'irs_exempt' => $settings->irsExempt(),
            'vat_exempt' => $settings->vatExempt(),
            'vat_exemption_threshold' => $settings->vatExemptionThreshold(),
            'payments_exempt_by_default' => $settings->paymentsExemptByDefault(),
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
                Section::make('Segurança Social')
                    ->description('Trabalhador independente: contribuição sobre o rendimento relevante de cada trimestre.')
                    ->icon(Phosphor::Bank)
                    ->columns(2)
                    ->schema([
                        DatePicker::make('activity_started_on')
                            ->label('Início de atividade')
                            ->native(false)
                            ->displayFormat('d/m/Y'),
                        Toggle::make('ss_first_year_exempt')
                            ->label('Isento nos primeiros 12 meses')
                            ->inline(false),
                        TextInput::make('ss_rate_percent')
                            ->label('Taxa contributiva')
                            ->numeric()
                            ->step(0.1)
                            ->suffix('%')
                            ->required(),
                        TextInput::make('ss_relevant_income_share_percent')
                            ->label('Rendimento relevante')
                            ->helperText('70% para prestação de serviços.')
                            ->numeric()
                            ->step(1)
                            ->suffix('%')
                            ->required(),
                    ]),
                Section::make('IRS e IVA')
                    ->icon(Phosphor::Receipt)
                    ->schema([
                        Toggle::make('irs_exempt')
                            ->label('Isento de IRS')
                            ->helperText('Desliga se passares a pagar IRS; a estimativa mostra então o rendimento tributável (coeficiente de 75%).'),
                        Toggle::make('payments_exempt_by_default')
                            ->label('Recebimentos isentos por defeito')
                            ->helperText('Os recebimentos e as avenças novos vêm marcados como isentos de impostos (não contam para a estimativa). Podes desmarcar caso a caso.'),
                        Toggle::make('vat_exempt')
                            ->label('Isento de IVA (art. 53.º)')
                            ->live(),
                        MoneyInput::make('vat_exemption_threshold')
                            ->label('Limite anual de isenção de IVA (art. 53.º)')
                            ->visible(fn ($get) => (bool) $get('vat_exempt'))
                            ->required(fn ($get) => (bool) $get('vat_exempt')),
                    ]),
                Section::make('As tuas contas no banco')
                    ->description('Para o extrato reconhecer as transferências entre a conta da empresa e as tuas outras contas ("Transferência minha"). O saldo da conta vem do extrato importado.')
                    ->icon(Phosphor::Wallet)
                    ->columnSpanFull()
                    ->schema([
                        Textarea::make('account_names')
                            ->label('Nomes das tuas contas, como aparecem no banco')
                            ->placeholder("ANA MARIA SILVA\nConta pessoal")
                            ->helperText('Um por linha: o nome do titular nas transferências e os nomes das tuas outras contas (ex.: Conta pessoal).')
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
                        Action::make('save')->label('Guardar')->submit('save'),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        app(FinanceSettings::class)->set([
            'activity_started_on' => $data['activity_started_on'] ?? null,
            'ss_first_year_exempt' => (bool) ($data['ss_first_year_exempt'] ?? false),
            'ss_rate' => round((float) $data['ss_rate_percent'] / 100, 4),
            'ss_relevant_income_share' => round((float) $data['ss_relevant_income_share_percent'] / 100, 4),
            'irs_exempt' => (bool) ($data['irs_exempt'] ?? false),
            'vat_exempt' => (bool) ($data['vat_exempt'] ?? false),
            'vat_exemption_threshold' => (int) ($data['vat_exemption_threshold'] ?? 0),
            'payments_exempt_by_default' => (bool) ($data['payments_exempt_by_default'] ?? false),
            'account_names' => implode("\n", array_filter(array_map('trim', preg_split('/\R/', (string) ($data['account_names'] ?? '')) ?: []))) ?: null,
        ]);

        Notification::make()->title('Definições guardadas')->success()->send();
    }
}
