<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Always calculated from amounts, payments and due dates; never stored or chosen by hand.
 */
enum ReceivableStatus: string implements HasColor, HasLabel
{
    case Paid = 'paid';
    case Partial = 'partial';
    case Open = 'open';
    case Overdue = 'overdue';

    public function getLabel(): string
    {
        return match ($this) {
            self::Paid => __('Paid'),
            self::Partial => __('Partial'),
            self::Open => __('Open'),
            self::Overdue => __('Past due'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Paid => 'success',
            self::Partial => 'warning',
            self::Open => 'gray',
            self::Overdue => 'danger',
        };
    }
}
