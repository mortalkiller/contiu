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
            self::Portugal => 'Portugal',
            self::EuropeanUnion => 'Outro país da UE',
            self::OutsideEu => 'Fora da UE',
        };
    }

    public function isForeign(): bool
    {
        return $this !== self::Portugal;
    }
}
