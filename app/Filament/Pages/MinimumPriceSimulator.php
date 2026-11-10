<?php

namespace App\Filament\Pages;

use App\Filament\Support\ProportionBar;
use App\Support\DocumentedTurnover;
use App\Support\MinimumPriceCalculator;
use App\Support\Money;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;
use Livewire\Attributes\Locked;
use ToneGabes\Filament\Icons\Enums\Phosphor;
use UnitEnum;

class MinimumPriceSimulator extends Page
{
    protected string $view = 'filament.pages.minimum-price-simulator';

    protected static ?string $title = 'Simulador de preço mínimo';

    protected static ?string $slug = 'simulador-preco-minimo';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::Calculator;

    protected static string|UnitEnum|null $navigationGroup = 'Análise';

    protected static ?int $navigationSort = 5;

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array{annual: int, monthly: int, hourly: int}|null */
    #[Locked]
    public ?array $result = null;

    /**
     * The validated inputs behind the current result, in the calculator's units.
     *
     * @var array{incomeGoal: int, costs: int, billableMonths: int, hoursPerMonth: int, reservePercent: int}|null
     */
    #[Locked]
    public ?array $inputs = null;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Dados da simulação')
                    ->columns(['md' => 2])
                    ->schema([
                        $this->decimalInput('incomeGoal', 9)
                            ->label('Meta de rendimento anual')
                            ->suffix('€')
                            ->helperText('Quanto queres que te sobre no ano depois de cobrir os custos e a reserva.')
                            ->validationMessages(['regex' => 'Introduz um valor entre 0 e 999 999 999,99 € com até duas casas decimais.']),
                        $this->decimalInput('costs', 9)
                            ->label('Custos anuais da atividade')
                            ->suffix('€')
                            ->helperText('Soma os custos do ano inteiro, incluindo os dos meses sem faturação.')
                            ->validationMessages(['regex' => 'Introduz um valor entre 0 e 999 999 999,99 € com até duas casas decimais.']),
                        TextInput::make('billableMonths')
                            ->label('Meses faturáveis por ano')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(12)
                            ->required()
                            ->validationMessages([
                                'integer' => 'Introduz um número inteiro de meses.',
                                'min' => 'Indica pelo menos um mês faturável.',
                                'max' => 'Indica no máximo 12 meses faturáveis.',
                            ]),
                        $this->decimalInput('hoursPerMonth', 4)
                            ->label('Horas por mês faturável')
                            ->suffix('h')
                            ->helperText('Conta apenas as horas que esperas poder cobrar.')
                            ->rule('not_regex:/\A0+(?:[,.]0+)?\z/')
                            ->validationMessages([
                                'regex' => 'Introduz um valor até 9 999,99 horas com até duas casas decimais.',
                                'not_regex' => 'Indica mais de zero horas faturáveis por mês.',
                            ]),
                        $this->decimalInput('reservePercent', 2)
                            ->label('Reserva sobre a faturação')
                            ->suffix('%')
                            ->helperText('Escolhe a percentagem da faturação que queres pôr de lado. Introduz 0 se não quiseres reservar nada.')
                            ->validationMessages(['regex' => 'Introduz uma percentagem entre 0 e 99,99 com até duas casas decimais.']),
                    ]),
            ]);
    }

    public function updatedData(): void
    {
        $this->result = null;
        $this->inputs = null;
    }

    /**
     * Where the yearly minimum goes: what you keep, what you set aside and what the activity costs.
     */
    public function breakdownBar(): ?HtmlString
    {
        if ($this->result === null || $this->inputs === null) {
            return null;
        }

        return ProportionBar::render([
            ['label' => 'Rendimento', 'cents' => $this->inputs['incomeGoal'], 'tone' => 'strong'],
            ['label' => 'Reserva', 'cents' => max(0, $this->result['annual'] - $this->inputs['incomeGoal'] - $this->inputs['costs']), 'tone' => 'soft'],
            ['label' => 'Custos', 'cents' => $this->inputs['costs'], 'tone' => 'clay'],
        ]);
    }

    /**
     * This year's documented billing against the yearly minimum, and the monthly pace still
     * needed from the current month to December. Null without a result or without billing in EUR.
     *
     * @return array{year: int, billed: string, percent: int, pace: ?string, reached: bool}|null
     */
    public function comparison(): ?array
    {
        $billed = DocumentedTurnover::forYear(today()->year)->billedNet;

        if ($this->result === null || $billed === null || $this->result['annual'] <= 0) {
            return null;
        }

        $missing = $this->result['annual'] - $billed;
        $monthsLeft = 13 - today()->month;

        return [
            'year' => today()->year,
            'billed' => Money::format($billed),
            'percent' => (int) min(100, max(0, floor($billed * 100 / $this->result['annual']))),
            'pace' => $missing > 0 ? $this->formatMinimum((int) ceil($missing / $monthsLeft)) : null,
            'reached' => $missing <= 0,
        ];
    }

    public function calculate(): void
    {
        $this->result = null;
        $this->inputs = null;
        $data = $this->form->getState();

        $this->result = MinimumPriceCalculator::calculate(
            annualIncomeGoalCents: $data['incomeGoal'],
            annualCostsCents: $data['costs'],
            billableMonths: (int) $data['billableMonths'],
            hoursPerMonthHundredths: $data['hoursPerMonth'],
            reserveBasisPoints: $data['reservePercent'],
        );

        $this->inputs = [
            'incomeGoal' => $data['incomeGoal'],
            'costs' => $data['costs'],
            'billableMonths' => (int) $data['billableMonths'],
            'hoursPerMonth' => $data['hoursPerMonth'],
            'reservePercent' => $data['reservePercent'],
        ];
    }

    public function formatMinimum(int $cents): string
    {
        $wholeEuros = Number::format(intdiv($cents, 100), 0, locale: 'pt_PT');

        return sprintf('%s,%02d €', $wholeEuros, $cents % 100);
    }

    private function decimalInput(string $name, int $wholeDigits): TextInput
    {
        return TextInput::make($name)
            ->inputMode('decimal')
            ->stripCharacters([' ', "\u{00A0}", "\u{202F}"])
            ->regex('/\A[0-9]{1,'.$wholeDigits.'}(?:[,.][0-9]{1,2})?\z/')
            ->required()
            ->dehydrateStateUsing(function (string $state): int {
                [$whole, $fraction] = array_pad(explode('.', str_replace(',', '.', $state)), 2, '');

                return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
            });
    }
}
