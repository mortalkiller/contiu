<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ExpenseAttribution: string implements HasLabel
{
    case Professional = 'professional';
    case Mixed = 'mixed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Professional => 'Profissional',
            self::Mixed => 'Mista',
        };
    }
}
