<?php

namespace App\Livewire;

use App\Filament\Pages\ClientRevenueQuality;
use App\Models\Client;
use App\Support\AccountantAccess;
use App\Support\CashForecast;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;
use App\Support\Money;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class ClientRevenueScenario extends Component implements HasSchemas
{
    use InteractsWithSchemas;

    public ?int $clientId = null;

    public ?string $delayDays = null;

    public function boot(): void
    {
        $account = CurrentAccount::get();

        abort_unless($account && ClientRevenueQuality::canAccess() && AccountantAccess::canResolve($account), 403);
    }

    public function scenarioForm(Schema $schema): Schema
    {
        $clients = once(fn (): array => Client::query()->orderBy('name')->pluck('name', 'id')->all());
        $clientId = $this->clientId;
        $delay = filter_var($this->delayDays, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => CashForecast::MAX_DELAY_DAYS],
        ]);
        $scenario = isset($clients[$clientId])
            ? once(fn (): array => (new CashForecast(app(FinanceSettings::class)))->clientInflowScenario($clientId, $delay === false ? null : $delay))
            : null;

        return $schema->components([
            Section::make(__('What if a client pays late?'))
                ->description(__('Compare inflows for this month and the next two without this client or with their payments delayed.'))
                ->extraAttributes(['class' => 'fap-key-value'])
                ->schema([
                    Grid::make(1)->schema([
                        Select::make('clientId')->label(__('Client'))->options($clients)->searchable()->live(),
                        TextInput::make('delayDays')
                            ->label(__('Simulated delay'))
                            ->suffix(__('days'))
                            ->integer()
                            ->minValue(0)
                            ->maxValue(CashForecast::MAX_DELAY_DAYS)
                            ->live(onBlur: true)
                            ->disabled($scenario === null)
                            ->helperText(filled($this->delayDays) && $delay === false
                                ? __('Enter a whole number of days between 0 and :max.', ['max' => CashForecast::MAX_DELAY_DAYS])
                                : null),
                    ]),
                    ...($scenario === null ? [] : [
                        TextEntry::make('projected')->inlineLabel()->label(__('Expected inflows'))->state(Money::format($scenario['inflows'], locale: app()->getLocale())),
                        TextEntry::make('clientProjected')->inlineLabel()->label(__('Inflows from this client'))->state(Money::format($scenario['clientInflows'], locale: app()->getLocale())),
                        TextEntry::make('otherProjected')->inlineLabel()->label(__('Inflows without this client'))->state(Money::format($scenario['withoutClient'], locale: app()->getLocale())),
                    ]),
                    ...(! isset($scenario['months']) ? [] : [
                        TextEntry::make('delayedProjected')->inlineLabel()->label(__('Inflows with delay'))->state(Money::format($scenario['withDelay'], locale: app()->getLocale())),
                        TextEntry::make('beyondHorizon')->inlineLabel()->label(__('Deferred beyond the forecast'))->state(Money::format($scenario['beyondHorizon'], locale: app()->getLocale())),
                        RepeatableEntry::make('delayMonths')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->state($scenario['months'])
                            ->table([
                                TableColumn::make(__('Month')),
                                TableColumn::make(__('Current forecast')),
                                TableColumn::make(__('With delay')),
                            ])
                            ->schema([
                                TextEntry::make('label'),
                                TextEntry::make('inflows')->money('EUR', divideBy: 100, locale: app()->getLocale()),
                                TextEntry::make('withDelay')->money('EUR', divideBy: 100, locale: app()->getLocale()),
                            ]),
                    ]),
                ]),
        ]);
    }

    public function render(): View
    {
        return view('livewire.client-revenue-scenario');
    }
}
