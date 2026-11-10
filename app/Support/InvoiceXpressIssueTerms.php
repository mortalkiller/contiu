<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class InvoiceXpressIssueTerms
{
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
            throw ValidationException::withMessages(['customer' => 'O cliente do InvoiceXpress deve estar identificado em Portugal com o mesmo NIF registado no Contiu.']);
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
            throw ValidationException::withMessages(['item' => 'O artigo aprovado deve existir e ter um preço em euros com até duas casas decimais e uma unidade válida.']);
        }

        return ['id' => $approvedId, 'name' => $name, 'description' => $description, 'unit' => $unit, 'unitCents' => $unitCents];
    }

    /** @return array{id: int, name: string, region: string, basisPoints: int} */
    public static function tax(array $taxes, string $name, string $region, int $basisPoints): array
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
        if ($name === '' || $id === null || $code === null || ($match['code'] ?? null) !== $code
            || ! $regionMatches || self::hundredths($match['value'] ?? null) !== $basisPoints) {
            throw ValidationException::withMessages(['tax' => 'O nome de IVA configurado deve identificar um único imposto com a região e a taxa esperadas.']);
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
            throw ValidationException::withMessages(['sequence' => 'A série aprovada deve estar registada na AT para este tipo de documento.']);
        }

        return ['id' => $documentSequenceId, 'name' => $name, 'validationCode' => $code];
    }

    /** @return list<array{itemId: int, quantity: int}> */
    public static function lineSelections(int $itemId, int $quantity, array $additionalLines, array $approvedItems, int $maxLines): array
    {
        if (! array_is_list($additionalLines) || count($additionalLines) >= $maxLines) {
            throw ValidationException::withMessages(['additionalLines' => "Indica entre 1 e {$maxLines} linhas."]);
        }

        $selection = [];

        foreach ([['itemId' => $itemId, 'quantity' => $quantity], ...$additionalLines] as $line) {
            if (! is_array($line) || count($line) !== 2
                || ! in_array($id = self::positiveId($line['itemId'] ?? null), $approvedItems, true)) {
                throw ValidationException::withMessages(['item' => 'Escolhe um artigo aprovado para cada linha.']);
            }

            $amount = self::positiveId($line['quantity'] ?? null);
            if ($amount === null || $amount > 1000) {
                throw ValidationException::withMessages(['quantity' => 'Indica uma quantidade inteira entre 1 e 1000 em cada linha.']);
            }

            $selection[] = ['itemId' => $id, 'quantity' => $amount];
        }

        return $selection;
    }

    public static function matchesLine(array $line, array $expected, array $item, array $tax, array $totals): bool
    {
        return ($line['name'] ?? null) === $expected['name']
            && ($line['description'] ?? '') === $expected['description']
            && ($line['unit'] ?? null) === $expected['unit']
            && self::hundredths($line['unit_price'] ?? null) === $item['unitCents']
            && self::hundredths($line['quantity'] ?? null) === $expected['quantity'] * 100
            && self::hundredths($line['discount'] ?? 0) === 0
            && self::hundredths($line['retention'] ?? 0) === 0
            && self::positiveId(data_get($line, 'tax.id')) === $tax['id']
            && data_get($line, 'tax.name') === $tax['name']
            && self::hundredths(data_get($line, 'tax.value')) === $tax['basisPoints']
            && self::hundredths($line['subtotal'] ?? null) === $totals['net']
            && self::hundredths($line['tax_amount'] ?? null) === $totals['tax']
            && self::hundredths($line['total'] ?? null) === $totals['total'];
    }

    /** @return array{net: int, tax: int, total: int} */
    public static function totals(int $unitCents, int $quantity, int $taxBasisPoints): array
    {
        if ($unitCents < 1 || $unitCents > 99_999_999 || $quantity < 1 || $quantity > 1000
            || $taxBasisPoints < 0 || $taxBasisPoints > 10000) {
            throw ValidationException::withMessages(['quantity' => 'Revê o preço, a quantidade inteira e a taxa do artigo.']);
        }

        $net = $unitCents * $quantity;
        $tax = intdiv($net * $taxBasisPoints + 5000, 10000);

        return ['net' => $net, 'tax' => $tax, 'total' => $net + $tax];
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
}
