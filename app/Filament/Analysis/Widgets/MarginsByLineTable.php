<?php

namespace App\Filament\Analysis\Widgets;

use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

class MarginsByLineTable extends MarginsTable
{
    use HasSkeletonPlaceholder;

    protected function heading(): string
    {
        return 'Por linha de negócio';
    }

    protected function groupLabel(): string
    {
        return 'Linha';
    }

    protected function groups(): array
    {
        return $this->report()->byBusinessLine();
    }
}
