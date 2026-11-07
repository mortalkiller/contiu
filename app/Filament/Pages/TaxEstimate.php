<?php

namespace App\Filament\Pages;

use App\Filament\Analysis\Widgets\TaxQuartersTable;
use App\Support\FinanceSettings;
use App\Support\Money;
use App\Support\PeriodSummary;
use App\Support\TaxEstimator;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Livewire\Attributes\Url;
use ToneGabes\Filament\Icons\Enums\Phosphor;
use UnitEnum;

/**
 * "Estimativa de impostos" for an ENI in the simplified regime. Estimates only.
 */
class TaxEstimate extends Page
{
    protected static ?string $title = 'Estimativa de impostos';

    protected static ?string $slug = 'estimativa-de-impostos';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::Calculator;

    protected static string|UnitEnum|null $navigationGroup = 'Análise';

    protected static ?int $navigationSort = 3;

    #[Url]
    public ?int $year = null;

    public function mount(): void
    {
        $this->year ??= today()->year;
    }

    public function getSubheading(): string
    {
        return 'ENI em regime simplificado. O rendimento considerado é o que recebeste no ano.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('settings')
                ->label('Definições fiscais')
                ->icon(Phosphor::Sliders)
                ->color('gray')
                ->url(FinanceSettingsPage::getUrl()),
        ];
    }

    public function content(Schema $schema): Schema
    {
        $estimator = new TaxEstimator(app(FinanceSettings::class), $this->year ?? today()->year);

        return $schema->components([
            Callout::make('Estimativa — confirma os valores com o teu contabilista.')
                ->description('Regras simplificadas: Segurança Social sobre 70% do rendimento de cada trimestre, IRS pelo coeficiente de 75% e o limite de isenção de IVA definido nas Definições fiscais.')
                ->icon(Phosphor::WarningCircle)
                ->status('warning'),
            Section::make()
                ->columns(['md' => 4])
                ->schema([
                    Select::make('year')
                        ->label('Ano')
                        ->options(PeriodSummary::availableYears())
                        ->selectablePlaceholder(false)
                        ->native(false)
                        ->live(),
                ]),
            Grid::make(['md' => 2, 'xl' => 4])->schema([
                Section::make('Rendimento')
                    ->icon(Phosphor::HandCoins)
                    ->schema([
                        TextEntry::make('income')->hiddenLabel()->state(Money::format($estimator->income()))->size(TextSize::Large)->weight('bold'),
                        TextEntry::make('income_note')->hiddenLabel()->state('Recebido em '.$estimator->year.' (proxy do rendimento relevante).')->color('gray'),
                        TextEntry::make('exempt_note')
                            ->hiddenLabel()
                            ->state($estimator->exemptIncome() > 0
                                ? 'Excluídos '.Money::format($estimator->exemptIncome()).' de recebimentos isentos.'
                                : 'Nenhum recebimento isento de impostos — marca-os em Recebimentos ou na avença.')
                            ->icon(Phosphor::ShieldCheck)
                            ->color($estimator->exemptIncome() > 0 ? 'info' : 'gray'),
                    ]),
                Section::make('Segurança Social')
                    ->icon(Phosphor::Bank)
                    ->schema([
                        TextEntry::make('ss')->hiddenLabel()->state(Money::format($estimator->ssYearTotal()))->size(TextSize::Large)->weight('bold'),
                        TextEntry::make('ss_note')->hiddenLabel()->color('gray')->state(match (true) {
                            $estimator->firstYearEndsOn() !== null && $estimator->settings()->ssFirstYearExempt() => 'Isento até '.$estimator->firstYearEndsOn()->format('d/m/Y').' (1.º ano).',
                            default => 'Estimativa do ano, pelos trimestres abaixo.',
                        }),
                    ]),
                Section::make('IRS')
                    ->icon(Phosphor::Receipt)
                    ->schema($estimator->irsExempt()
                        ? [
                            TextEntry::make('irs')->hiddenLabel()->state(Money::format(0))->size(TextSize::Large)->weight('bold'),
                            TextEntry::make('irs_note')->hiddenLabel()->state('Isento (definição)')->color('gray'),
                        ]
                        : [
                            TextEntry::make('irs')->hiddenLabel()->state(Money::format($estimator->irsTaxableIncome()))->size(TextSize::Large)->weight('bold'),
                            TextEntry::make('irs_note')->hiddenLabel()->state('Rendimento tributável (75%). O imposto depende das tabelas de IRS e não é calculado.')->color('gray'),
                        ]),
                Section::make('IVA')
                    ->icon(Phosphor::Percent)
                    ->schema($estimator->vatExempt()
                        ? [
                            TextEntry::make('vat')->hiddenLabel()->state(number_format($estimator->vatThresholdPercent(), 1, ',', ' ').'%')->size(TextSize::Large)->weight('bold')
                                ->color(match ($estimator->vatThresholdStatus()) {
                                    'danger' => 'danger',
                                    'warning' => 'warning',
                                    default => 'success',
                                }),
                            TextEntry::make('vat_note')->hiddenLabel()->color('gray')
                                ->state('Limite de isenção de IVA: '.Money::format($estimator->income()).' de '.Money::format($estimator->settings()->vatExemptionThreshold()).'.'),
                        ]
                        : [
                            TextEntry::make('vat')->hiddenLabel()->state(Money::format($estimator->vatBalance()))->size(TextSize::Large)->weight('bold'),
                            TextEntry::make('vat_note')->hiddenLabel()->color('gray')
                                ->state('IVA liquidado '.Money::format($estimator->vatCollected()).' − IVA dedutível '.Money::format($estimator->vatPaid()).'.'),
                        ]),
            ]),
            ...match (true) {
                $estimator->vatExempt() && $estimator->vatThresholdStatus() === 'danger' => [
                    Callout::make('Ultrapassaste o limite de isenção de IVA')
                        ->description('Fala com o teu contabilista: pode ser preciso passar ao regime normal de IVA.')
                        ->icon(Phosphor::WarningCircle)
                        ->status('danger'),
                ],
                $estimator->vatExempt() && $estimator->vatThresholdStatus() === 'warning' => [
                    Callout::make('Estás perto do limite de isenção de IVA')
                        ->description('Já usaste '.number_format($estimator->vatThresholdPercent(), 1, ',', ' ').'% do limite anual.')
                        ->icon(Phosphor::WarningCircle)
                        ->status('warning'),
                ],
                default => [],
            },
            ...$this->getWidgetsSchemaComponents([TaxQuartersTable::class], ['year' => $this->year]),
        ]);
    }
}
