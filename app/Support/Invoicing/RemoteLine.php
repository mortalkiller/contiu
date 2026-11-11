<?php

namespace App\Support\Invoicing;

/** One line as the provider holds it: quantity in thousandths, price in cents, rates in basis points. */
final readonly class RemoteLine
{
    public function __construct(
        public string $description,
        public int $quantity,
        public string $unit,
        public int $unitPrice,
        public int $discountBasisPoints = 0,
        public int $vatBasisPoints = 0,
    ) {}
}
