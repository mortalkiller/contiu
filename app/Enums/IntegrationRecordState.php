<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** How a record at the provider compares with what the app holds. */
enum IntegrationRecordState: string implements HasColor, HasLabel
{
    case InSync = 'in_sync';
    case Differs = 'differs';
    case RemoteOnly = 'remote_only';

    public function getLabel(): string
    {
        return match ($this) {
            self::InSync => __('In sync'),
            self::Differs => __('Different total'),
            self::RemoteOnly => __('Only at the provider'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::InSync => 'success',
            self::Differs => 'warning',
            self::RemoteOnly => 'info',
        };
    }
}
