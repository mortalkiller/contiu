<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum FiscalProfileViesStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Unavailable = 'unavailable';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => __('Awaiting verification'),
            self::Valid => __('Valid'),
            self::Invalid => __('Invalid'),
            self::Unavailable => __('Service unavailable'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Valid => 'success',
            self::Invalid => 'danger',
            self::Unavailable => 'warning',
        };
    }
}
