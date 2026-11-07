<?php

namespace App\Filament\Analysis\Widgets;

use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

class MarginsByClientTable extends MarginsTable
{
    use HasSkeletonPlaceholder;

    protected function heading(): string
    {
        return 'Por cliente';
    }

    protected function groupLabel(): string
    {
        return 'Cliente';
    }

    protected function groups(): array
    {
        return $this->report()->byClient();
    }
}
