<?php

namespace App\Filament\Support;

use App\Support\Money;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;

/**
 * "Em aberto" as every list in the mocks shows it: in bold while there is something to receive
 * or pay, a grey dash once it is settled. The state is in cents.
 */
final class OutstandingColumn
{
    public static function make(string $name = 'outstanding_amount'): TextColumn
    {
        return TextColumn::make($name)
            ->label(__('Outstanding'))
            ->money('EUR', divideBy: 100)
            ->alignEnd()
            ->weight(fn (?int $state): ?FontWeight => $state > 0 ? FontWeight::SemiBold : null)
            ->color(fn (?int $state): ?string => $state > 0 ? null : 'gray')
            ->formatStateUsing(fn (?int $state): string => $state > 0 ? Money::format($state) : '—');
    }
}
