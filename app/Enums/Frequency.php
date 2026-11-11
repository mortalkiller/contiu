<?php

namespace App\Enums;

use App\Support\Money;
use Filament\Support\Contracts\HasLabel;

enum Frequency: string implements HasLabel
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';

    public function getLabel(): string
    {
        return match ($this) {
            self::Monthly => __('Monthly'),
            self::Quarterly => __('Quarterly'),
            self::Yearly => __('Annual'),
        };
    }

    /** An amount billed once per period, spread per month and rounded half up (184,50 € quarterly → 61,50 €). */
    public function perMonth(int $cents): int
    {
        return Money::round($cents / $this->months());
    }

    /** An amount billed once per period, over a year (20 € monthly → 240 €). */
    public function perYear(int $cents): int
    {
        return Money::round($cents * 12 / $this->months());
    }

    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::Yearly => 12,
        };
    }
}
