<?php

namespace App\Filament\Analysis\Widgets;

class MarginsByLineTable extends MarginsTable
{
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
