<?php

namespace App\Filament\Widgets\Concerns;

use App\Support\Period;
use App\Support\PeriodSummary;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Dashboard widgets follow the page filters: a year, and optionally a month of it.
 */
trait UsesDashboardPeriod
{
    use InteractsWithPageFilters;

    protected function period(): Period
    {
        return Period::of(
            (int) ($this->pageFilters['year'] ?? today()->year),
            filled($this->pageFilters['month'] ?? null) ? (int) $this->pageFilters['month'] : null,
        );
    }

    protected function summary(): PeriodSummary
    {
        return new PeriodSummary($this->period());
    }
}
