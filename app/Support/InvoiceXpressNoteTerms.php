<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class InvoiceXpressNoteTerms
{
    public const array Types = ['credit_note' => 'CreditNote', 'debit_note' => 'DebitNote'];

    public const array Issued = ['final', 'sent', 'settled'];

    /**
     * @return list<array{article: array{name: string, description: string, unit: string, unitCents: int},
     *     tax: array{id: int, name: string, basisPoints: int}, quantity: int, totals: array{net: int, tax: int, total: int}}>
     */
    public static function lines(array $document): array
    {
        $items = $document['items'] ?? null;

        if (! is_array($items) || ! array_is_list($items) || $items === [] || count($items) > 100
            || ! in_array($document['currency'] ?? null, ['Euro', 'EUR'], true)
            || ! self::zero($document['discount'] ?? 0) || ! self::zero($document['retention'] ?? 0)
            || ! in_array($document['tax_exemption'] ?? null, [null, '', 'M00'], true)) {
            throw self::invalid('originalId', 'O documento contém moeda, descontos, retenção ou isenção fora da emissão assistida.');
        }

        $lines = array_map(self::line(...), $items);
        foreach (['net' => 'before_taxes', 'tax' => 'taxes', 'total' => 'total'] as $key => $remote) {
            if (InvoiceXpressIssueTerms::hundredths($document[$remote] ?? null) !== array_sum(array_column(array_column($lines, 'totals'), $key))) {
                throw self::invalid('originalId', 'Os totais do documento não coincidem com as linhas verificadas.');
            }
        }

        return $lines;
    }

    public static function originalLineIndex(array $lines, array $line): int
    {
        $matches = array_keys(array_filter($lines, fn (array $original): bool => $original['article'] === $line['article'] && $original['tax'] === $line['tax']));

        if (count($matches) !== 1) {
            throw self::invalid('line', 'Não foi possível distinguir a linha original de forma inequívoca.');
        }

        return $matches[0];
    }

    public static function sequence(array $sequence, int $id, string $kind): array
    {
        $name = $sequence['serie'] ?? null;
        $documentId = InvoiceXpressIssueTerms::positiveId($sequence["current_{$kind}_sequence_id"] ?? null);
        $code = $sequence["current_{$kind}_validation_code"] ?? null;

        if (InvoiceXpressIssueTerms::positiveId($sequence['id'] ?? null) !== $id || $documentId === null
            || ! is_string($name) || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,34}\z/', $name)
            || ! is_string($code) || ! preg_match('/\A[A-Z0-9]{8,32}\z/', $code)) {
            throw self::invalid('kind', 'A série configurada tem de estar registada na AT para este tipo de nota.');
        }

        return ['id' => $documentId, 'name' => $name, 'validationCode' => $code];
    }

    private static function line(mixed $line): array
    {
        if (! is_array($line)) {
            throw self::invalid('line', 'A linha do documento não é válida.');
        }

        $quantityHundredths = InvoiceXpressIssueTerms::hundredths($line['quantity'] ?? null);
        $taxId = InvoiceXpressIssueTerms::positiveId(data_get($line, 'tax.id'));
        $taxName = data_get($line, 'tax.name');
        $rate = InvoiceXpressIssueTerms::hundredths(data_get($line, 'tax.value'));

        if ($quantityHundredths === null || $quantityHundredths % 100 !== 0 || $taxId === null
            || ! is_string($taxName) || trim($taxName) === '' || mb_strlen($taxName) > 255
            || ! in_array($rate, [600, 1300, 2300], true)
            || ! self::zero($line['discount'] ?? 0) || ! self::zero($line['discount_amount'] ?? 0)) {
            throw self::invalid('line', 'A emissão assistida requer quantidade inteira e uma linha com IVA positivo, sem desconto.');
        }

        $article = InvoiceXpressIssueTerms::item(['id' => 1, ...array_intersect_key($line, array_flip(['name', 'description', 'unit', 'unit_price']))], 1);
        unset($article['id']);
        $quantity = intdiv($quantityHundredths, 100);
        $totals = InvoiceXpressIssueTerms::totals($article['unitCents'], $quantity, $rate);

        foreach (['net' => 'subtotal', 'tax' => 'tax_amount', 'total' => 'total'] as $key => $remote) {
            if (InvoiceXpressIssueTerms::hundredths($line[$remote] ?? null) !== $totals[$key]) {
                throw self::invalid('line', 'Os valores da linha não coincidem com a quantidade, o preço e o IVA.');
            }
        }

        return ['article' => $article, 'tax' => ['id' => $taxId, 'name' => $taxName, 'basisPoints' => $rate], 'quantity' => $quantity, 'totals' => $totals];
    }

    private static function zero(mixed $value): bool
    {
        return InvoiceXpressIssueTerms::hundredths($value) === 0;
    }

    public static function invalid(string $key, string $message): ValidationException
    {
        return ValidationException::withMessages([$key => $message]);
    }
}
