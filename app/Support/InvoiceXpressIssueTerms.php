<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class InvoiceXpressIssueTerms
{
    public const array WithholdingRates = [1150 => '11,5%', 2300 => '23%'];

    /** @return array{id: int, name: string, nif: string} */
    public static function customer(array $remote, int $customerId, string $localNif): array
    {
        $nif = self::nif($localNif);
        $remoteNif = self::nif($remote['fiscal_id'] ?? null);
        $country = $remote['country'] ?? null;
        $name = $remote['name'] ?? null;

        if ($customerId < 1 || self::positiveId($remote['id'] ?? null) !== $customerId || $nif === null || $nif !== $remoteNif
            || ! in_array($country, ['PT', 'Portugal'], true) || ! is_string($name) || trim($name) === ''
            || ! mb_check_encoding($name) || mb_strlen($name) > 255 || str_contains($name, "\0")) {
            throw ValidationException::withMessages(['customer' => __('The InvoiceXpress customer must be identified in Portugal with the same tax ID recorded in Contiu.')]);
        }

        return ['id' => $customerId, 'name' => trim($name), 'nif' => $nif];
    }

    public static function nif(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/\A(?:PT)?([1-9][0-9]{8})\z/i', trim($value), $parts) || $parts[1] === '999999990') {
            return null;
        }

        $nif = $parts[1];
        $weightedSum = 0;

        for ($index = 0; $index < 8; $index++) {
            $weightedSum += (int) $nif[$index] * (9 - $index);
        }

        return ((11 - $weightedSum % 11) % 11) % 10 === (int) $nif[8] ? $nif : null;
    }

    /** @return array{id: int, name: string, description: string, unit: string, unitCents: int} */
    public static function item(array $remote, int $approvedId): array
    {
        $name = $remote['name'] ?? null;
        $description = $remote['description'] ?? '';
        $unit = $remote['unit'] ?? null;
        $unitCents = self::hundredths($remote['unit_price'] ?? null);

        if (self::positiveId($remote['id'] ?? null) !== $approvedId
            || ! is_string($name) || trim($name) !== $name || $name === '' || mb_strlen($name) > 60
            || ! is_string($description) || mb_strlen($description) > 200 || substr_count($description, "\n") >= 20
            || ! in_array($unit, ['hour', 'day', 'month', 'unit', 'service', 'tax', 'other', 'product', 'article'], true)
            || $unitCents === null || $unitCents === 0) {
            throw ValidationException::withMessages(['item' => __('The approved item must exist and have a price in euros with up to two decimal places and a valid unit.')]);
        }

        return ['id' => $approvedId, 'name' => $name, 'description' => $description, 'unit' => $unit, 'unitCents' => $unitCents];
    }

    /** @return array{id: int, name: string, region: string, basisPoints: int} */
    public static function tax(array $taxes, string $name, string $region, int $basisPoints, bool $draftOnly = false): array
    {
        $matches = array_values(array_filter($taxes, fn (mixed $tax): bool => is_array($tax) && ($tax['name'] ?? null) === $name));
        $match = count($matches) === 1 ? $matches[0] : [];
        $id = self::positiveId($match['id'] ?? null);
        $code = match ($basisPoints) {
            0 => 'ISE',
            600 => 'RED',
            1300 => 'INT',
            2300 => 'NOR',
            default => null,
        };

        $regionMatches = ($match['region'] ?? null) === $region
            || ($basisPoints === 0 && $name === 'Isento' && in_array($match['region'] ?? null, [null, ''], true));
        $codeMatches = ($match['code'] ?? null) === $code || ($draftOnly && ($match['code'] ?? null) === null);
        if ($name === '' || $id === null || $code === null || ! $codeMatches
            || ! $regionMatches || self::hundredths($match['value'] ?? null) !== $basisPoints) {
            throw ValidationException::withMessages(['tax' => __('The configured VAT name must identify a single tax with the expected region and rate.')]);
        }

        return ['id' => $id, 'name' => $name, 'region' => $region, 'basisPoints' => $basisPoints];
    }

    /** @return array{id: int, name: string, validationCode: string} */
    public static function sequence(array $remote, int $sequenceId, string $kind = 'invoice'): array
    {
        $name = $remote['serie'] ?? null;
        $documentSequenceId = self::positiveId($remote["current_{$kind}_sequence_id"] ?? null);
        $code = $remote["current_{$kind}_validation_code"] ?? null;

        if (! isset(InvoiceXpressIssue::DocumentTypes[$kind]) || self::positiveId($remote['id'] ?? null) !== $sequenceId
            || $documentSequenceId === null || ($kind === 'invoice' && $documentSequenceId !== $sequenceId)
            || ! is_string($name) || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,34}\z/', $name)
            || ! is_string($code) || ! preg_match('/\A[A-Z0-9]{8,32}\z/', $code)) {
            throw ValidationException::withMessages(['sequence' => __('The approved series must be registered with the Portuguese Tax Authority for this document type.')]);
        }

        return ['id' => $documentSequenceId, 'name' => $name, 'validationCode' => $code];
    }

    /** @return list<array{itemId: int, quantity: int, discountBasisPoints?: int}> */
    public static function lineSelections(int $itemId, int $quantity, array $additionalLines, array $approvedItems, int $maxLines, int $discountBasisPoints = 0): array
    {
        if (! array_is_list($additionalLines) || count($additionalLines) >= $maxLines) {
            throw ValidationException::withMessages(['additionalLines' => __('Enter between 1 and :max lines.', ['max' => $maxLines])]);
        }

        $selection = [];

        foreach ([['itemId' => $itemId, 'quantity' => $quantity, 'discountBasisPoints' => $discountBasisPoints], ...$additionalLines] as $line) {
            if (! is_array($line) || array_diff_key($line, ['itemId' => true, 'quantity' => true, 'discountBasisPoints' => true]) !== []
                || ! in_array($id = self::positiveId($line['itemId'] ?? null), $approvedItems, true)) {
                throw ValidationException::withMessages(['item' => __('Choose an approved item for each line.')]);
            }

            $amount = self::positiveId($line['quantity'] ?? null);
            if ($amount === null || $amount > 1000) {
                throw ValidationException::withMessages(['quantity' => __('Enter a whole quantity between 1 and 1,000 on each line.')]);
            }

            $discount = array_key_exists('discountBasisPoints', $line) ? $line['discountBasisPoints'] : 0;
            if (! is_int($discount)) {
                $discount = $discount === '0' ? 0 : self::positiveId($discount);
            }
            self::validateDiscount($discount);

            $selection[] = [
                'itemId' => $id,
                'quantity' => $amount,
                ...($discount === 0 ? [] : ['discountBasisPoints' => $discount]),
            ];
        }

        return $selection;
    }

    public static function matchesLine(array $line, array $expected, array $item, array $tax, array $totals, int $withholdingBasisPoints = 0): bool
    {
        $discount = self::hundredths($expected['discount'] ?? 0);

        return ($line['name'] ?? null) === $expected['name']
            && ($line['description'] ?? '') === $expected['description']
            && ($line['unit'] ?? null) === $expected['unit']
            && self::hundredths($line['unit_price'] ?? null) === $item['unitCents']
            && self::hundredths($line['quantity'] ?? null) === $expected['quantity'] * 100
            && $discount !== null && $discount <= 10000
            && self::hundredths($line['discount'] ?? 0) === $discount
            && (! array_key_exists('discount_amount', $line) || self::roundedLineAmount($line['discount_amount']) === ($totals['discount'] ?? 0))
            && in_array(self::hundredths($line['retention'] ?? 0), [0, $withholdingBasisPoints], true)
            && self::positiveId(data_get($line, 'tax.id')) === $tax['id']
            && data_get($line, 'tax.name') === $tax['name']
            && self::hundredths(data_get($line, 'tax.value')) === $tax['basisPoints']
            && self::roundedLineAmount($line['subtotal'] ?? null) === $totals['net']
            && self::roundedLineAmount($line['tax_amount'] ?? null) === $totals['tax']
            && self::roundedLineAmount($line['total'] ?? null) === $totals['total'];
    }

    public static function matchesAmounts(array $document, array $totals, ?array $withholding): bool
    {
        return self::hundredths($document['before_taxes'] ?? null) === $totals['net']
            && self::hundredths($document['taxes'] ?? null) === $totals['tax']
            && self::hundredths($document['total'] ?? null) === ($withholding['cash'] ?? $totals['total'])
            && self::hundredths($document['retention'] ?? 0) === ($withholding['basisPoints'] ?? 0);
    }

    public static function matchesDocumentDiscount(array $document, int $lineDiscountCents): bool
    {
        return in_array(self::hundredths($document['discount'] ?? 0), [0, $lineDiscountCents], true);
    }

    /** @return array{basisPoints: int, gross: int, retained: int, cash: int}|null */
    public static function withholding(int $basisPoints, int $net, int $gross): ?array
    {
        if ($basisPoints === 0) {
            return null;
        }

        if (! isset(self::WithholdingRates[$basisPoints])) {
            throw ValidationException::withMessages(['withholdingRate' => __('Choose the withholding rate confirmed for the service.')]);
        }

        $amounts = self::withholdingAmounts($basisPoints, $net, $gross);
        if ($amounts['retained'] < 2500) {
            throw ValidationException::withMessages(['withholdingRate' => __('The calculated withholding is below €25. Review the withholding exemption for this document.')]);
        }

        return $amounts;
    }

    /** @return array{basisPoints: int, gross: int, retained: int, cash: int} */
    public static function withholdingAmounts(int $basisPoints, int $net, int $gross): array
    {
        $retained = intdiv($net * $basisPoints + 5000, 10000);

        return ['basisPoints' => $basisPoints, 'gross' => $gross, 'retained' => $retained, 'cash' => $gross - $retained];
    }

    /** @return array{net: int, tax: int, total: int, discount?: int} */
    public static function totals(int $unitCents, int $quantity, int $taxBasisPoints, int $discountBasisPoints = 0): array
    {
        if ($unitCents < 1 || $unitCents > 99_999_999 || $quantity < 1 || $quantity > 1000
            || $taxBasisPoints < 0 || $taxBasisPoints > 10000) {
            throw ValidationException::withMessages(['quantity' => __('Review the price, whole quantity and tax rate for the item.')]);
        }

        self::validateDiscount($discountBasisPoints);

        $gross = $unitCents * $quantity;
        $discount = intdiv($gross * $discountBasisPoints + 5000, 10000);
        $net = $gross - $discount;
        $tax = intdiv($net * $taxBasisPoints + 5000, 10000);

        return [
            'net' => $net,
            'tax' => $tax,
            'total' => $net + $tax,
            ...($discount === 0 ? [] : ['discount' => $discount]),
        ];
    }

    private static function validateDiscount(?int $discountBasisPoints): void
    {
        if ($discountBasisPoints === null || $discountBasisPoints < 0 || $discountBasisPoints > 10000) {
            throw ValidationException::withMessages(['discountBasisPoints' => __('Enter a discount between 0% and 100% with up to two decimal places.')]);
        }
    }

    /** Exact two-decimal provider values. No rounding of unexpected remote precision. */
    public static function hundredths(mixed $value): ?int
    {
        if (is_float($value)) {
            $value = json_encode($value);
        }

        if ((! is_string($value) && ! is_int($value))
            || ! preg_match('/\A([0-9]{1,12})(?:[.,]([0-9]{1,2})0*)?\z/', (string) $value, $parts)) {
            return null;
        }

        return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
    }

    public static function positiveId(mixed $value): ?int
    {
        if (! is_int($value) && (! is_string($value) || ! preg_match('/\A[1-9][0-9]{0,17}\z/', $value))) {
            return null;
        }

        return (int) $value > 0 ? (int) $value : null;
    }

    /** InvoiceXpress returns calculated line amounts with sub-cent precision. */
    private static function roundedLineAmount(mixed $value): ?int
    {
        if (is_float($value)) {
            $value = json_encode($value);
        }

        if ((! is_string($value) && ! is_int($value))
            || ! preg_match('/\A([0-9]{1,12})(?:[.,]([0-9]+))?\z/', (string) $value, $parts)) {
            return null;
        }

        $thousandths = (int) str_pad(substr($parts[2] ?? '', 0, 3), 3, '0');

        return (int) $parts[1] * 100 + intdiv($thousandths + 5, 10);
    }
}
