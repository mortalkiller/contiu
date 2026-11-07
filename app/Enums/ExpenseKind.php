<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

enum ExpenseKind: string implements HasColor, HasDescription, HasLabel
{
    case Direct = 'direct';
    case Overhead = 'overhead';

    public function getLabel(): string
    {
        return match ($this) {
            self::Direct => 'Direta',
            self::Overhead => 'Estrutura',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Direct => 'Custo de um trabalho ou avença específica; conta para a margem dele.',
            self::Overhead => 'Ferramentas, taxas e custos gerais; contam só para o resultado da empresa.',
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
