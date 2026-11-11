<?php

namespace App\Filament\Analysis\Widgets;

use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

class MarginsByClientTable extends MarginsTable
{
    use HasSkeletonPlaceholder;

    protected function heading(): string
    {
        return __('By client');
    }

    protected function groupLabel(): string
    {
        return __('Client');
    }

    protected function groups(): array
    {
        return $this->report()->byClient();
    }
}
