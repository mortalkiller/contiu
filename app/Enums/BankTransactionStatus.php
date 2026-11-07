<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum BankTransactionStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Matched = 'matched';
    case OwnTransfer = 'own_transfer';
    case Ignored = 'ignored';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Por tratar',
            self::Matched => 'Registado',
            self::OwnTransfer => 'Transferência minha',
            self::Ignored => 'Ignorado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Matched => 'success',
            self::OwnTransfer => 'info',
            self::Ignored => 'gray',
        };
    }
}
