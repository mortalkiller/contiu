<?php

namespace App\Support;

use App\Enums\PortugueseVatRegion;
use Illuminate\Validation\ValidationException;

final class InvoiceXpressNoteTerms
{
    public const array Types = ['credit_note' => 'CreditNote', 'debit_note' => 'DebitNote'];

    public const array Issued = ['final', 'sent', 'settled'];

    /**
     * @return list<array{article: array{name: string, description: string, unit: string, unitCents: int},
     *     tax: array{id: int, name: string, basisPoints: int, region?: string, code?: string, category?: string}, quantity: int,
     *     totals: array{net: int, tax: int, total: int, discount?: int}, discountBasisPoints?: int,
     *     exemptionReason?: string, withholdingBasisPoints?: int, lineRetentionBasisPoints?: int}>
     */
    public static function lines(array $document, array $recordedTaxLines = []): array
    {
        $items = $document['items'] ?? null;
        $rate = InvoiceXpressIssueTerms::hundredths($document['retention'] ?? 0);

        if (! is_array($items) || ! array_is_list($items) || $items === [] || count($items) > 100
            || ! in_array($document['currency'] ?? null, ['Euro', 'EUR'], true)
            || $rate === null || ($rate !== 0 && ! isset(InvoiceXpressIssueTerms::WithholdingRates[$rate]))
            || ! in_array($document['tax_exemption'] ?? null, [null, '', 'M00', 'M10', 'M40'], true)) {
            throw self::invalid('originalId', __('The document currency, exemption or withholding rate is outside the scope of assisted note issuance.'));
        }

        $reason = in_array($document['tax_exemption'] ?? null, ['M10', 'M40'], true) ? $document['tax_exemption'] : null;
        if (count($recordedTaxLines) !== count($items) && array_any($recordedTaxLines,
            fn (array $line): bool => isset($line['tax']['region']) || isset($line['tax']['code']))) {
            throw self::invalid('originalId', __('The original InvoiceXpress invoice does not match the Contiu record.'));
        }
        $lines = [];
        foreach ($items as $index => $item) {
            $lines[] = self::line($item, $reason, $rate, $recordedTaxLines[$index]['tax'] ?? []);
        }
        if (! InvoiceXpressIssueTerms::matchesDocumentDiscount($document, array_sum(array_column(array_column($lines, 'totals'), 'discount')))) {
            throw self::invalid('originalId', __('The document discount does not match the verified lines.'));
        }
        $totals = [];
        foreach (['net', 'tax', 'total'] as $key) {
            $totals[$key] = array_sum(array_column(array_column($lines, 'totals'), $key));
        }
        $withholding = $rate === 0 ? null : InvoiceXpressIssueTerms::withholdingAmounts($rate, $totals['net'], $totals['total']);
        $lineRetention = array_sum(array_map(fn (array $line): int => self::withholding($line)['retained'] ?? 0, $lines));
        if (! InvoiceXpressIssueTerms::matchesAmounts($document, $totals, $withholding)
            || ($withholding['retained'] ?? 0) !== $lineRetention) {
            throw self::invalid('originalId', __('The document gross amount, withholding and net payable amount do not reconcile to the cent.'));
        }

        return $lines;
    }

    public static function originalLineIndex(array $lines, array $line): int
    {
        $matches = array_keys(array_filter($lines, fn (array $original): bool => $original['article'] === $line['article']
            && self::matchesTax($original['tax'], $line['tax']) && ($original['discountBasisPoints'] ?? 0) === ($line['discountBasisPoints'] ?? 0)
            && ($original['exemptionReason'] ?? null) === ($line['exemptionReason'] ?? null)
            && ($original['withholdingBasisPoints'] ?? 0) === ($line['withholdingBasisPoints'] ?? 0)));

        if (count($matches) !== 1) {
            throw self::invalid('line', __('Could not identify the original line unambiguously.'));
        }

        return $matches[0];
    }

    public static function sequence(array $sequence, int $id, string $kind, bool $draftOnly = false): array
    {
        if ($draftOnly) {
            return InvoiceXpressSandboxDrafts::sequence($sequence, $id, $kind);
        }

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

    private static function line(mixed $line, ?string $exemptionReason, int $documentRate, array $recordedTax): array
    {
        if (! is_array($line)) {
            throw self::invalid('line', __('The document line is invalid.'));
        }

        $quantityHundredths = InvoiceXpressIssueTerms::hundredths($line['quantity'] ?? null);
        $taxId = InvoiceXpressIssueTerms::positiveId(data_get($line, 'tax.id'));
        $taxName = data_get($line, 'tax.name');
        $rate = InvoiceXpressIssueTerms::hundredths(data_get($line, 'tax.value'));
        $providerTax = self::withRecordedTax($line['tax'] ?? [], $recordedTax);
        $regionValue = $providerTax['region'] ?? null;
        $region = is_string($regionValue) ? PortugueseVatRegion::tryFrom($regionValue) : null;
        $category = $rate === null ? null : ($region ?? PortugueseVatRegion::Mainland)->categoryFor($rate);
        $code = $rate === 0 ? 'ISE' : match ($category) {
            'reduced' => 'RED',
            'intermediate' => 'INT',
            'standard' => 'NOR',
            default => null,
        };
        $providerCode = $providerTax['code'] ?? null;
        $supportedRate = $rate === 0 || $category !== null
            || ($region === null && $rate !== null
                && array_any(PortugueseVatRegion::cases(), fn (PortugueseVatRegion $candidate): bool => $candidate->categoryFor($rate) !== null));
        $withholdingRate = InvoiceXpressIssueTerms::hundredths($line['retention'] ?? 0);
        $discount = $line['discount'] ?? 0;
        $discountBasisPoints = InvoiceXpressIssueTerms::hundredths($discount);

        if ($quantityHundredths === null || $quantityHundredths % 100 !== 0 || $taxId === null
            || ! is_string($taxName) || trim($taxName) === '' || mb_strlen($taxName) > 255
            || ! $supportedRate
            || ($regionValue !== null && $regionValue !== '' && $region === null)
            || ($providerCode !== null && $providerCode !== '' && ! in_array($providerCode, $code === null ? ['RED', 'INT', 'NOR'] : [$code], true))
            || (($rate === 0) !== ($exemptionReason !== null))
            || ! in_array($withholdingRate, [0, $documentRate], true)
            || $discountBasisPoints === null || $discountBasisPoints > 10000) {
            throw self::invalid('line', __('Assisted notes require a whole quantity, verified VAT or exemption, and valid discount and withholding rates.'));
        }

        $article = InvoiceXpressIssueTerms::item(['id' => 1, ...array_intersect_key($line, array_flip(['name', 'description', 'unit', 'unit_price']))], 1);
        unset($article['id']);
        $quantity = intdiv($quantityHundredths, 100);
        $tax = [
            'id' => $taxId, 'name' => $taxName, 'basisPoints' => $rate,
            ...($region === null ? [] : ['region' => $region->value]),
            ...($region === null || $category === null ? [] : ['category' => $category]),
            ...($region === null && ($providerCode === null || $providerCode === '') ? [] : ['code' => $code ?? $providerCode]),
        ];
        $totals = InvoiceXpressIssueTerms::totals($article['unitCents'], $quantity, $rate, $discountBasisPoints);

        if (! InvoiceXpressIssueTerms::matchesLine($line, [...$article, 'quantity' => $quantity, 'discount' => $discount], $article, $tax, $totals, $documentRate)) {
            throw self::invalid('line', __('The line amounts do not match the quantity, price, discount and VAT.'));
        }

        return [
            'article' => $article,
            'tax' => $tax,
            'quantity' => $quantity,
            'totals' => $totals,
            ...($discountBasisPoints === 0 ? [] : ['discountBasisPoints' => $discountBasisPoints]),
            ...($exemptionReason === null ? [] : ['exemptionReason' => $exemptionReason]),
            ...($documentRate === 0 ? [] : ['withholdingBasisPoints' => $documentRate]),
            ...($withholdingRate === 0 ? [] : ['lineRetentionBasisPoints' => $withholdingRate]),
        ];
    }

    private static function withRecordedTax(mixed $tax, array $recorded): array
    {
        $tax = is_array($tax) ? $tax : [];
        $id = InvoiceXpressIssueTerms::positiveId($recorded['id'] ?? null);
        $basisPoints = InvoiceXpressIssueTerms::hundredths($recorded['value'] ?? null);
        if ($id === null || $basisPoints === null || ! is_string($recorded['name'] ?? null)
            || (! isset($recorded['region']) && ! isset($recorded['code']))) {
            return $tax;
        }

        if (! InvoiceXpressImport::sameLineTax($tax, ['id' => $id, 'name' => $recorded['name'], 'basisPoints' => $basisPoints])) {
            throw self::invalid('originalId', __('The original InvoiceXpress invoice does not match the Contiu record.'));
        }

        foreach (['region', 'code'] as $field) {
            $value = $recorded[$field] ?? null;
            if ($value === null) {
                continue;
            }

            if (isset($tax[$field]) && $tax[$field] !== '' && $tax[$field] !== $value) {
                throw self::invalid('originalId', __('The original InvoiceXpress invoice does not match the Contiu record.'));
            }

            $tax[$field] = $value;
        }

        return $tax;
    }

    private static function matchesTax(array $expected, array $actual): bool
    {
        return $actual['id'] === $expected['id']
            && $actual['name'] === $expected['name']
            && $actual['basisPoints'] === $expected['basisPoints']
            && InvoiceXpressIssueTerms::matchesTaxRegion($actual['region'] ?? null, $expected['region'] ?? null)
            && (! isset($expected['code'], $actual['code']) || $actual['code'] === $expected['code']);
    }

    /** @return array{basisPoints: int, gross: int, retained: int, cash: int}|null */
    public static function withholding(array $line, ?array $totals = null): ?array
    {
        $rate = $line['withholdingBasisPoints'] ?? 0;
        if ($rate === 0) {
            return null;
        }

        $totals ??= $line['totals'];

        return InvoiceXpressIssueTerms::withholdingAmounts($rate, $totals['net'], $totals['total']);
    }

    /** The original issued document supplies the fiscal decision; no current regime is inferred. */
    public static function customer(array $remote, int $id, string $localTaxId, ?string $reason = null, bool $draftOnly = false): array
    {
        if ($reason !== 'M40') {
            $testId = strtoupper(trim($localTaxId));
            if ($draftOnly && InvoiceXpressIssueTerms::nif($localTaxId) === null
                && preg_match('/\A[A-Z0-9]{1,32}\z/', $testId)
                && is_string($remote['fiscal_id'] ?? null) && strtoupper(trim($remote['fiscal_id'])) === $testId
                && in_array($remote['country'] ?? null, ['PT', 'Portugal'], true)) {
                return [...InvoiceXpressSandboxDrafts::customer($remote, $id), 'nif' => $testId];
            }

            return InvoiceXpressIssueTerms::customer($remote, $id, $localTaxId);
        }

        $vat = InvoiceXpressEuService::catalogVat($remote);
        $localVat = InvoiceXpressEuService::catalogVat([...$remote, 'fiscal_id' => $localTaxId]);
        if ($vat === null || $vat !== $localVat) {
            throw self::invalid('customer', __('The note customer must match the country and tax ID of the original invoice.'));
        }
        $country = substr($vat, 0, 2);

        return InvoiceXpressEuService::customer($remote, $id, ['country' => $country === 'EL' ? 'GR' : $country, 'vat' => $vat]);
    }

    /** A related-document lookup can supply the original binding if the provider omits the owner. */
    public static function matchesDocument(array $note, array $snapshot, int $id, string $reference, bool $related = false): bool
    {
        $original = $snapshot['original'];
        $input = $snapshot['input'];
        $owner = $note['owner_invoice_id'] ?? null;
        $sequenceMatches = array_key_exists('sequence_id', $note)
            ? InvoiceXpressIssueTerms::positiveId($note['sequence_id']) === $snapshot['sequence']['id']
            : ($snapshot['draftOnly'] ?? false) && $snapshot['sequence']['name'] === InvoiceXpressSandboxDrafts::Series && ($note['status'] ?? null) === 'draft';
        $expectedLine = $original['lines'][$input['line'] - 1];
        if ($id === $original['providerId'] || InvoiceXpressIssueTerms::positiveId($note['id'] ?? null) !== $id
            || ($owner === null ? ! $related : InvoiceXpressIssueTerms::positiveId($owner) !== $original['providerId'])
            || ($note['type'] ?? null) !== self::Types[$input['kind']]
            || ! is_string($note['status'] ?? null)
            || ! $sequenceMatches
            || ($note['date'] ?? null) !== $snapshot['payload']['date']
            || ($note['observations'] ?? null) !== $input['reason']
            || ($note['reference'] ?? null) !== $reference) {
            return false;
        }

        try {
            $lines = self::lines($note);
            $client = is_array($note['client'] ?? null) ? $note['client'] : [];
            $customer = self::customer($client, $original['customer']['id'], $original['customer']['nif'], $expectedLine['exemptionReason'] ?? null, $snapshot['draftOnly'] ?? false);
        } catch (ValidationException) {
            return false;
        }

        if (! self::matchesTax($expectedLine['tax'], $lines[0]['tax'])) {
            return false;
        }

        $lines[0]['tax'] = $expectedLine['tax'];
        unset($expectedLine['lineRetentionBasisPoints'], $lines[0]['lineRetentionBasisPoints']);

        return $customer === $original['customer']
            && $lines === [[...$expectedLine, 'quantity' => $input['quantity'], 'totals' => $snapshot['totals']]];
    }

    public static function invalid(string $key, string $message): ValidationException
    {
        return ValidationException::withMessages([$key => $message]);
    }
}
