<?php

namespace App\Support;

use App\Models\FiscalDocumentLine;

/**
 * The amounts of a fiscal document, worked out from its lines. The one place that does this sum:
 * the form's preview, what is saved and what is checked against the billing provider all use it.
 *
 * Every amount is in cents, rounded half up. VAT is charged per rate on the sum of that rate's
 * lines, as invoicing software does, so the lines' own VAT can differ from it by a cent.
 */
final readonly class FiscalDocumentTotals
{
    /** @param list<array{gross: int, discount: int, net: int, vat: int, total: int}> $lines */
    private function __construct(
        public array $lines,
        public int $gross,
        public int $discount,
        public int $net,
        public int $vat,
        public int $total,
        public int $withholding,
    ) {}

    /** @param iterable<array{quantity: int, unit_price: int, discount_basis_points?: int|null, vat_basis_points?: int|null}> $lines */
    public static function of(iterable $lines, int $withholdingBasisPoints = 0): self
    {
        $amounts = [];
        $netByRate = [];

        foreach ($lines as $line) {
            $rate = (int) ($line['vat_basis_points'] ?? 0);
            $amount = self::line((int) $line['quantity'], (int) $line['unit_price'], (int) ($line['discount_basis_points'] ?? 0), $rate);
            $amounts[] = $amount;
            $netByRate[$rate] = ($netByRate[$rate] ?? 0) + $amount['net'];
        }

        $net = array_sum($netByRate);
        $vat = 0;

        foreach ($netByRate as $rate => $rateNet) {
            $vat += self::share($rateNet, $rate);
        }

        return new self(
            lines: $amounts,
            gross: array_sum(array_column($amounts, 'gross')),
            discount: array_sum(array_column($amounts, 'discount')),
            net: $net,
            vat: $vat,
            total: $net + $vat,
            withholding: FiscalTerms::withholdingAmount($withholdingBasisPoints, $net),
        );
    }

    /**
     * One line: the quantity is in thousandths, the price in cents and the rates in basis points.
     *
     * @return array{gross: int, discount: int, net: int, vat: int, total: int}
     */
    public static function line(int $quantity, int $unitPrice, int $discountBasisPoints = 0, int $vatBasisPoints = 0): array
    {
        $gross = intdiv($quantity * $unitPrice + intdiv(FiscalDocumentLine::QUANTITY_SCALE, 2), FiscalDocumentLine::QUANTITY_SCALE);
        $discount = self::share($gross, $discountBasisPoints);
        $net = $gross - $discount;
        $vat = self::share($net, $vatBasisPoints);

        return ['gross' => $gross, 'discount' => $discount, 'net' => $net, 'vat' => $vat, 'total' => $net + $vat];
    }

    /** What the client pays: the total less what they withhold. */
    public function payable(): int
    {
        return $this->total - $this->withholding;
    }

    private static function share(int $amount, int $basisPoints): int
    {
        return intdiv($amount * $basisPoints + 5000, 10000);
    }
}
