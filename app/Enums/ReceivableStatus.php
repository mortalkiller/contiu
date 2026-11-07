<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * Always calculated from amounts, payments and due dates; never stored or chosen by hand.
 */
enum ReceivableStatus: string implements HasColor, HasIcon, HasLabel
{
    case Paid = 'paid';
    case Partial = 'partial';
    case Open = 'open';
    case Overdue = 'overdue';

    public function getLabel(): string
    {
        return match ($this) {
            self::Paid => 'Pago',
            self::Partial => 'Parcial',
            self::Open => 'Em aberto',
            self::Overdue => 'Vencido',
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

    public function getIcon(): Phosphor
    {
        return match ($this) {
            self::Paid => Phosphor::CheckCircle,
            self::Partial => Phosphor::CircleHalf,
            self::Open => Phosphor::Clock,
            self::Overdue => Phosphor::WarningCircle,
        };
    }
}
