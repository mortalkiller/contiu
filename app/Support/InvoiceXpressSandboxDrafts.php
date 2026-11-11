<?php

namespace App\Support;

use App\Exceptions\InvoiceXpressException;
use Illuminate\Validation\ValidationException;

final class InvoiceXpressSandboxDrafts
{
    public const string Series = 'INVOICEXPRESSDEMO';

    public const array DocumentTypes = ['invoice' => 'Invoice', 'invoice_receipt' => 'Invoice receipt'];

    public function __construct(private readonly InvoiceXpressClient $client) {}

    /** @return array{id: int, name: string} */
    public static function sequence(array $series, int $seriesId, string $type): array
    {
        $documentSeriesId = InvoiceXpressIssueTerms::positiveId($series["current_{$type}_sequence_id"] ?? null);
        if (! isset(self::DocumentTypes[$type]) || ($series['serie'] ?? null) !== self::Series
            || InvoiceXpressIssueTerms::positiveId($series['id'] ?? null) !== $seriesId || $documentSeriesId === null) {
            throw ValidationException::withMessages(['sequence' => __('The demo series is unavailable for this document.')]);
        }

        return ['id' => $documentSeriesId, 'name' => self::Series];
    }

    /** @return array{id: int, name: string, nif: ?string} */
    public static function customer(array $customer, int $customerId): array
    {
        $name = $customer['name'] ?? null;
        if (InvoiceXpressIssueTerms::positiveId($customer['id'] ?? null) !== $customerId
            || ! is_string($name) || trim($name) === '' || mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['customer' => __('Choose an existing customer in the sandbox.')]);
        }

        return ['id' => $customerId, 'name' => trim($name), 'nif' => InvoiceXpressIssueTerms::nif($customer['fiscal_id'] ?? null)];
    }

    /** @param list<int> $itemIds */
    public function create(string $account, string $type, array $invoice, array $itemIds, string $revision, array $totals, ?array $withholding): int
    {
        abort_unless(InvoiceXpressIssueRepository::ownerCanIssue() && InvoiceXpressClient::sandboxDraftsAllowed($account), 403);

        $before = [];
        foreach ($itemIds as $index => $id) {
            $before[$id] = $this->readItem($account, $id);
            $item = InvoiceXpressIssueTerms::item($before[$id], $id);
            $line = $invoice['items'][$index];
            if ($line['name'] !== $item['name'] || $line['description'] !== $item['description'] || $line['unit'] !== $item['unit']
                || InvoiceXpressIssueTerms::hundredths($line['unit_price']) !== $item['unitCents']
                || ($line['tax']['name'] ?? null) !== ($before[$id]['tax']['name'] ?? null)) {
                throw ValidationException::withMessages(['confirmation' => __('The item has changed in InvoiceXpress. Preview the document again.')]);
            }
        }

        $response = $this->client->createSandboxDraft($type, [
            $type => $invoice,
            'proprietary_uid' => $revision,
        ], $account);

        $draft = $response[$type] ?? [];
        $id = InvoiceXpressIssueTerms::positiveId($draft['id'] ?? null);
        if ($id === null || ($draft['status'] ?? null) !== 'draft') {
            throw new InvoiceXpressException(__('InvoiceXpress did not confirm the draft. Check the documents in the sandbox.'));
        }

        foreach ($before as $itemId => $original) {
            try {
                $after = $this->readItem($account, $itemId);
            } catch (InvoiceXpressException) {
                throw new InvoiceXpressException(__('Draft #:id created. Could not look up the items in InvoiceXpress again.', ['id' => $id]));
            }

            if ($after !== $original) {
                throw new InvoiceXpressException(__('Draft #:id created. An item changed in InvoiceXpress. Check the catalog.', ['id' => $id]));
            }
        }

        if (! InvoiceXpressIssueTerms::matchesAmounts($draft, $totals, $withholding)) {
            throw new InvoiceXpressException(__('Draft #:id created. The totals or withholding returned by InvoiceXpress differ from the preview.', ['id' => $id]));
        }

        return $id;
    }

    private function readItem(string $account, int $id): array
    {
        return $this->client->request('GET', "/items/{$id}.json", expectedSubdomain: $account)['item'] ?? [];
    }
}
