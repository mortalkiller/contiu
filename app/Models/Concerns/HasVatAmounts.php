<?php

namespace App\Models\Concerns;

use App\Support\Money;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * Models with `net_amount` (cents) and `vat_rate` (fraction, e.g. 0.23) expose `vat_amount` and
 * `total`, in cents, VAT rounded per document as on the invoice.
 */
trait HasVatAmounts
{
    protected function vatAmount(): Attribute
    {
        return Attribute::get(fn (): int => Money::vat((int) $this->net_amount, (string) $this->vat_rate));
    }

    protected function total(): Attribute
    {
        return Attribute::get(fn (): int => (int) $this->net_amount + $this->vat_amount);
    }
}
