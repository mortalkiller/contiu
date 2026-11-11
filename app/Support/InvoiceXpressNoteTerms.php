<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class InvoiceXpressNoteTerms
{
    public const array Types = ['credit_note' => 'CreditNote', 'debit_note' => 'DebitNote'];

    public const array Issued = ['final', 'sent', 'settled'];

    /**
     * @return list<array{article: array{name: string, description: string, unit: string, unitCents: int},
     *     tax: array{id: int, name: string, basisPoints: int}, quantity: int,
     *     totals: array{net: int, tax: int, total: int, discount?: int}, discountBasisPoints?: int}>
     */
    public static function lines(array $document): array
    {
        $items = $document['items'] ?? null;

        if (! is_array($items) || ! array_is_list($items) || $items === [] || count($items) > 100
            || ! in_array($document['currency'] ?? null, ['Euro', 'EUR'], true)
            || ! self::zero($document['retention'] ?? 0)
            || ! in_array($document['tax_exemption'] ?? null, [null, '', 'M00'], true)) {
            throw self::invalid('originalId', __('The document contains currency, discounts, withholding or an exemption outside the scope of assisted issuance.'));
        }

        $lines = array_map(self::line(...), $items);
        if (! InvoiceXpressIssueTerms::matchesDocumentDiscount($document, array_sum(array_column(array_column($lines, 'totals'), 'discount')))) {
            throw self::invalid('originalId', __('The document discount does not match the verified lines.'));
        }
        foreach (['net' => 'before_taxes', 'tax' => 'taxes', 'total' => 'total'] as $key => $remote) {
            if (InvoiceXpressIssueTerms::hundredths($document[$remote] ?? null) !== array_sum(array_column(array_column($lines, 'totals'), $key))) {
                throw self::invalid('originalId', __('The document totals do not match the verified lines.'));
            }
        }

        return $lines;
    }

    public static function originalLineIndex(array $lines, array $line): int
    {
        $matches = array_keys(array_filter($lines, fn (array $original): bool => $original['article'] === $line['article']
            && $original['tax'] === $line['tax'] && ($original['discountBasisPoints'] ?? 0) === ($line['discountBasisPoints'] ?? 0)));

        if (count($matches) !== 1) {
            throw self::invalid('line', __('Could not identify the original line unambiguously.'));
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
            throw self::invalid('kind', __('The configured series must be registered with the Portuguese Tax Authority for this note type.'));
        }

        return ['id' => $documentId, 'name' => $name, 'validationCode' => $code];
    }

    private static function line(mixed $line): array
    {
        if (! is_array($line)) {
            throw self::invalid('line', __('The document line is invalid.'));
        }

        $quantityHundredths = InvoiceXpressIssueTerms::hundredths($line['quantity'] ?? null);
        $taxId = InvoiceXpressIssueTerms::positiveId(data_get($line, 'tax.id'));
        $taxName = data_get($line, 'tax.name');
        $rate = InvoiceXpressIssueTerms::hundredths(data_get($line, 'tax.value'));
        $discount = $line['discount'] ?? 0;
        $discountBasisPoints = InvoiceXpressIssueTerms::hundredths($discount);

        if ($quantityHundredths === null || $quantityHundredths % 100 !== 0 || $taxId === null
            || ! is_string($taxName) || trim($taxName) === '' || mb_strlen($taxName) > 255
            || ! in_array($rate, [600, 1300, 2300], true)
            || $discountBasisPoints === null || $discountBasisPoints > 10000) {
            throw self::invalid('line', __('Assisted issuance requires a whole quantity, positive VAT and a valid line discount.'));
        }

        $article = InvoiceXpressIssueTerms::item(['id' => 1, ...array_intersect_key($line, array_flip(['name', 'description', 'unit', 'unit_price']))], 1);
        unset($article['id']);
        $quantity = intdiv($quantityHundredths, 100);
        $tax = ['id' => $taxId, 'name' => $taxName, 'basisPoints' => $rate];
        $totals = InvoiceXpressIssueTerms::totals($article['unitCents'], $quantity, $rate, $discountBasisPoints);

        if (! InvoiceXpressIssueTerms::matchesLine($line, [...$article, 'quantity' => $quantity, 'discount' => $discount], $article, $tax, $totals)) {
            throw self::invalid('line', __('The line amounts do not match the quantity, price, discount and VAT.'));
        }

        return [
            'article' => $article,
            'tax' => $tax,
            'quantity' => $quantity,
            'totals' => $totals,
            ...($discountBasisPoints === 0 ? [] : ['discountBasisPoints' => $discountBasisPoints]),
        ];
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
