<?php

namespace App\Support;

use App\Exceptions\InvoiceXpressException;
use Illuminate\Validation\ValidationException;

final class InvoiceXpressCatalog
{
    public function __construct(private readonly InvoiceXpressClient $client) {}

    /** @return array{account: string, clients: array<int, string>, items: array<int, string>, taxes: list<string>, sequences: array<int, string>, draftSequences: array<int, string>} */
    public function choices(): array
    {
        abort_unless(InvoiceXpressIssueRepository::ownerCanIssue(), 403);

        $subdomain = $this->client->configuration()->subdomain;
        $sandbox = InvoiceXpressClient::sandboxDraftsAllowed($subdomain);
        $draftOnly = $sandbox && (InvoiceXpressIssue::options()['draftOnly'] ?? false);
        $clients = [];
        foreach ($this->list('clients', $subdomain, paginated: true) as $client) {
            $id = InvoiceXpressIssueTerms::positiveId($client['id'] ?? null);
            $country = $client['country'] ?? null;
            $nif = in_array($country, [null, '', 'PT', 'Portugal'], true)
                ? InvoiceXpressIssueTerms::nif($client['fiscal_id'] ?? null)
                : InvoiceXpressEuService::catalogVat($client);
            $name = $client['name'] ?? null;

            if ($id !== null && ($nif !== null || $draftOnly) && is_string($name) && trim($name) !== '') {
                $clients[$id] = $nif === null ? trim($name) : trim($name).' · '.$nif;
            }
        }

        $items = [];
        foreach ($this->list('items', $subdomain, paginated: true) as $item) {
            $id = InvoiceXpressIssueTerms::positiveId($item['id'] ?? null);
            $name = $item['name'] ?? null;

            if ($id !== null && is_string($name) && trim($name) !== '') {
                $items[$id] = $name;
            }
        }

        $taxes = [];
        foreach ($this->list('taxes', $subdomain) as $tax) {
            $name = $tax['name'] ?? null;
            $rate = InvoiceXpressIssueTerms::hundredths($tax['value'] ?? null);
            $region = $tax['region'] ?? null;
            if (is_string($name) && trim($name) !== '' && (
                ($region === 'PT' && in_array($rate, [600, 1300, 2300], true))
                || ($rate === 0 && ($tax['code'] ?? null) === 'ISE'
                    && ($region === 'PT' || ($name === 'Isento' && in_array($region, [null, ''], true))))
            )) {
                $taxes[] = $name;
            }
        }

        $sequences = [];
        $draftSequences = [];
        foreach ($this->list('sequences', $subdomain) as $sequence) {
            $id = InvoiceXpressIssueTerms::positiveId($sequence['id'] ?? null);
            if ($id === null) {
                continue;
            }
            if ($sandbox && ($sequence['serie'] ?? null) === InvoiceXpressSandboxDrafts::Series
                && InvoiceXpressIssueTerms::positiveId($sequence['current_invoice_sequence_id'] ?? null) !== null) {
                $draftSequences[$id] = InvoiceXpressSandboxDrafts::Series;

                continue;
            }
            foreach (array_keys(InvoiceXpressIssue::DocumentTypes) as $type) {
                try {
                    $valid = InvoiceXpressIssueTerms::sequence($sequence, $id, $type);
                    $sequences[$id] = $valid['name'];
                    break;
                } catch (ValidationException) {
                    continue;
                }
            }
        }

        return [
            'account' => $subdomain,
            'clients' => $clients,
            'items' => $items,
            'taxes' => array_values(array_unique($taxes)),
            'sequences' => $sequences,
            'draftSequences' => $draftSequences,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function contacts(string $subdomain): array
    {
        return $this->list('clients', $subdomain, paginated: true, verifyPagination: true);
    }

    /** @return list<array<string, mixed>> */
    private function list(string $resource, string $subdomain, bool $paginated = false, bool $verifyPagination = false): array
    {
        $entries = [];
        $perPage = 30;

        for ($page = 1; $page <= InvoiceXpressClient::MAX_PAGES; $page++) {
            $response = $this->client->request(
                'GET',
                "/{$resource}.json",
                $paginated ? ['page' => $page, 'per_page' => $perPage] : [],
                expectedSubdomain: $subdomain,
            );
            $rows = $response[$resource] ?? null;

            if (! is_array($rows) || ! array_is_list($rows) || array_filter($rows, fn (mixed $row): bool => ! is_array($row)) !== []) {
                throw new InvoiceXpressException(__('InvoiceXpress returned an invalid list.'));
            }

            array_push($entries, ...$rows);

            $lastPage = $response['pagination']['total_pages'] ?? null;
            if ($verifyPagination) {
                $currentPage = $response['pagination']['current_page'] ?? null;
                if (! is_int($lastPage) || $lastPage < 0
                    || ($currentPage !== $page && ! ($page === 1 && $currentPage === 0 && $lastPage === 0 && $rows === []))
                    || ($page < $lastPage && $rows === [])) {
                    throw new InvoiceXpressException(__('The InvoiceXpress customer list is incomplete.'));
                }
            }
            if (! $paginated || (is_int($lastPage) ? $page >= $lastPage : count($rows) < $perPage)) {
                return $entries;
            }
        }

        throw new InvoiceXpressException(__('The InvoiceXpress catalog exceeded the lookup limit.'));
    }
}
