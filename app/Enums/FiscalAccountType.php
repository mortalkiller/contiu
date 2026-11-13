<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum FiscalAccountType: string implements HasLabel
{
    case IndependentWorker = 'ti';
    case SoleTrader = 'eni';

    public function getLabel(): string
    {
        return match ($this) {
            self::IndependentWorker => __('Independent worker (TI)'),
            self::SoleTrader => __('Sole trader (ENI)'),
        };
    }
}
