<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum FiscalDocumentStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case Voided = 'voided';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'Ativo',
            self::Voided => 'Anulado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Voided => 'gray',
        };
    }
}
