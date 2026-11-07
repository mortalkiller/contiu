<?php

namespace App\Filament\Analysis\Concerns;

use App\Support\MarginReport;
use App\Support\Period;
use Livewire\Attributes\Reactive;

trait UsesMarginPeriod
{
    #[Reactive]
    public ?int $year = null;

    #[Reactive]
    public ?int $month = null;

    protected function period(): Period
    {
        return Period::of($this->year ?? today()->year, $this->month ?: null);
    }

    protected function report(): MarginReport
    {
        return new MarginReport($this->period());
    }
}
