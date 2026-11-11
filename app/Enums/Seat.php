<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Where a client or supplier is established, which decides the VAT treatment. */
enum Seat: string implements HasLabel
{
    case Portugal = 'pt';
    case EuropeanUnion = 'eu';
    case OutsideEu = 'non_eu';

    public function getLabel(): string
    {
        return match ($this) {
            self::Portugal => __('Portugal'),
            self::EuropeanUnion => __('Other EU country'),
            self::OutsideEu => __('Outside the EU'),
        };
    }

    public function isForeign(): bool
    {
        return $this !== self::Portugal;
    }
}
