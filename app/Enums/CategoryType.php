<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CategoryType: string implements HasColor, HasLabel
{
    case Income = 'income';
    case Expense = 'expense';

    public function getLabel(): string
    {
        return match ($this) {
            self::Income => __('Income'),
            self::Expense => __('Expense'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Income => 'success',
            self::Expense => 'danger',
        };
    }
}
