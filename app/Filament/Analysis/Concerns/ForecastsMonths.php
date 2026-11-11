<?php

namespace App\Filament\Analysis\Concerns;

use App\Support\CashForecast;
use Livewire\Attributes\Reactive;

trait ForecastsMonths
{
    #[Reactive]
    public int $months = 3;

    protected function forecast(): CashForecast
    {
        return CashForecast::forRequest($this->months);
    }
}
