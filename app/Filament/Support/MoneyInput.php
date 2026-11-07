<?php

namespace App\Filament\Support;

use App\Support\Money;
use Filament\Forms\Components\TextInput;

/**
 * A money field: the user types and reads euros, the record stores cents. Filled from a record (or
 * fillForm) the state arrives in cents and is shown in euros; on save it goes back to cents.
 * Inside the form (live closures, $get) the state is in euros.
 */
final class MoneyInput
{
    public static function make(string $name): TextInput
    {
        return TextInput::make($name)
            ->numeric()
            ->step(0.01)
            ->suffix('€')
            ->formatStateUsing(fn ($state) => filled($state) ? Money::toEuros((int) $state) : null)
            ->dehydrateStateUsing(fn ($state) => Money::toCents($state));
    }
}
