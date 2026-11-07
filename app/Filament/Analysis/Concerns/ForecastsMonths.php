<?php

namespace App\Filament\Analysis\Concerns;

use App\Support\CashForecast;
use App\Support\FinanceSettings;
use Livewire\Attributes\Reactive;

trait ForecastsMonths
{
    #[Reactive]
    public int $months = 3;

    protected function forecast(): CashForecast
    {
        return new CashForecast(app(FinanceSettings::class), $this->months);
    }
}
