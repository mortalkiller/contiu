<?php

namespace App\Filament\Analysis\Widgets;

use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

class MarginsByLineTable extends MarginsTable
{
    use HasSkeletonPlaceholder;

    protected function heading(): string
    {
        return __('By business line');
    }

    protected function groupLabel(): string
    {
        return __('Line');
    }

    protected function groups(): array
    {
        return $this->report()->byBusinessLine();
    }
}
