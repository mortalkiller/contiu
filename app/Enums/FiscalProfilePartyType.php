<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum FiscalProfilePartyType: string implements HasLabel
{
    case Individual = 'individual';
    case Business = 'business';

    public function getLabel(): string
    {
        return match ($this) {
            self::Individual => 'Pessoa singular',
            self::Business => 'Organização',
        };
    }
}
