<?php

namespace App\Enums;

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

    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::Yearly => 12,
        };
    }
}
