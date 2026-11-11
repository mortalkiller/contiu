<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** What an integration is for. An account connects one provider per category at a time. */
enum IntegrationCategory: string implements HasLabel
{
    case Invoicing = 'invoicing';

    public function getLabel(): string
    {
        return match ($this) {
            self::Invoicing => __('Invoicing'),
        };
    }
}
