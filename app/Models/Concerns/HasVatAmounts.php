<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * Models with `net_amount` and `vat_rate` (fraction, e.g. 0.23) expose `vat_amount` and `total`.
 */
trait HasVatAmounts
{
    protected function vatAmount(): Attribute
    {
        return Attribute::get(fn (): float => round((float) $this->net_amount * (float) $this->vat_rate, 2));
    }

    protected function total(): Attribute
    {
        return Attribute::get(fn (): float => round((float) $this->net_amount + $this->vat_amount, 2));
    }
}
