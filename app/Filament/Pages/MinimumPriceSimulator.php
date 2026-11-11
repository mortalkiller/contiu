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
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;
use Livewire\Attributes\Locked;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class MinimumPriceSimulator extends Page
{
    protected string $view = 'filament.pages.minimum-price-simulator';

    protected static ?string $slug = 'simulador-preco-minimo';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::Calculator;

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
                Section::make(__('Scenario inputs'))
                    ->columns(['md' => 2])
                    ->schema([
                        $this->decimalInput('incomeGoal', 9)
                            ->label(__('Annual income target'))
                            ->suffix('€')
                            ->helperText(__('How much you want left for the year after covering costs and the reserve.'))
                            ->validationMessages(['regex' => __('Enter an amount between €0 and €999,999,999.99 with up to two decimal places.')]),
                        $this->decimalInput('costs', 9)
                            ->label(__('Annual business costs'))
                            ->suffix('€')
                            ->helperText(__('Add up the full year’s costs, including months with no invoicing.'))
                            ->validationMessages(['regex' => __('Enter an amount between €0 and €999,999,999.99 with up to two decimal places.')]),
                        TextInput::make('billableMonths')
                            ->label(__('Billable months per year'))
                            ->integer()
                            ->minValue(1)
                            ->maxValue(12)
                            ->required()
                            ->validationMessages([
                                'integer' => __('Enter a whole number of months.'),
                                'min' => __('Enter at least one billable month.'),
                                'max' => __('Enter no more than 12 billable months.'),
                            ]),
                        $this->decimalInput('hoursPerMonth', 4)
                            ->label(__('Hours per billable month'))
                            ->suffix('h')
                            ->helperText(__('Count only the hours you expect to bill.'))
                            ->rule('not_regex:/\A0+(?:[,.]0+)?\z/')
                            ->validationMessages([
                                'regex' => __('Enter up to 9,999.99 hours with up to two decimal places.'),
                                'not_regex' => __('Enter more than zero billable hours per month.'),
                            ]),
                        $this->decimalInput('reservePercent', 2)
                            ->label(__('Reserve from invoiced revenue'))
                            ->suffix('%')
                            ->helperText(__('Choose the percentage of invoiced revenue to set aside. Enter 0 if you want to set aside nothing.'))
                            ->validationMessages(['regex' => __('Enter a percentage between 0 and 99.99 with up to two decimal places.')]),
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
            ['label' => __('Earnings'), 'cents' => $this->inputs['incomeGoal'], 'tone' => 'strong'],
            ['label' => __('Reserve'), 'cents' => max(0, $this->result['annual'] - $this->inputs['incomeGoal'] - $this->inputs['costs']), 'tone' => 'soft'],
            ['label' => __('Costs'), 'cents' => $this->inputs['costs'], 'tone' => 'clay'],
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
            'billed' => Money::format($billed, locale: app()->getLocale()),
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
        $locale = app()->getLocale();
        $wholeEuros = Number::format(intdiv($cents, 100), 0, locale: $locale);

        return $locale === 'en_US'
            ? sprintf('€%s.%02d', $wholeEuros, $cents % 100)
            : sprintf('%s,%02d €', $wholeEuros, $cents % 100);
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

    #[Unexposed]
    public function getTitle(): string
    {
        return __('Minimum price calculator');
    }

    #[Unexposed]
    public static function getNavigationGroup(): string
    {
        return __('Analysis');
    }

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('Minimum price calculator');
    }
}
