<?php

namespace App\Support;

use Illuminate\Support\Number;

final class Money
{
    /** "1 965,50 €" */
    public static function format(float|int|string|null $amount): string
    {
        return (string) Number::currency((float) $amount, 'EUR', 'pt_PT');
    }

    /**
     * VAT options keyed like the `decimal:4` cast returns them, so stored values match.
     *
     * @return array<string, string>
     */
    public static function vatOptions(): array
    {
        return ['0.0000' => '0%', '0.0600' => '6%', '0.1300' => '13%', '0.2300' => '23%'];
    }
}
