<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

enum ExpenseKind: string implements HasColor, HasDescription, HasLabel
{
    // Overhead first: it is the usual kind and the default, as the expense form shows them.
    case Overhead = 'overhead';
    case Direct = 'direct';

    public function getLabel(): string
    {
        return match ($this) {
            self::Direct => __('Direct'),
            self::Overhead => __('Overhead'),
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Direct => __('A cost linked to a specific project or retainer. It counts toward its margin.'),
            self::Overhead => __('Tools, fees, and general costs. They count toward the business result.'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Direct => 'primary',
            self::Overhead => 'clay',
        };
    }
}
