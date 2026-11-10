<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum FiscalDocumentDirection: string implements HasLabel
{
    case Issued = 'issued';
    case Received = 'received';

    public function getLabel(): string
    {
        return match ($this) {
            self::Issued => 'Emitido',
            self::Received => 'Recebido',
        };
    }
}
