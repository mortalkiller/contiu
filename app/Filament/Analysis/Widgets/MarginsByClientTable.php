<?php

namespace App\Filament\Analysis\Widgets;

class MarginsByClientTable extends MarginsTable
{
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
