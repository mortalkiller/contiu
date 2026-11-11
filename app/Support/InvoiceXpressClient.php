<?php

namespace App\Support;

use App\Exceptions\AccountIsReadOnly;
use App\Exceptions\InvoiceXpressException;
use App\Models\InvoiceXpressConfig;
use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Throwable;

final class InvoiceXpressClient
{
    public const int PER_PAGE = 20;

    public const int MAX_PAGES = 25;

    public static function catalogValidatedFor(string $subdomain): bool
    {
        return in_array($subdomain, config('services.invoicexpress.catalog_safe_accounts', []), true);
    }

    public static function sandboxDraftsAllowed(?string $subdomain): bool
    {
        return $subdomain !== null && app()->environment('local', 'testing')
            && $subdomain === config('services.invoicexpress.sandbox_draft_account');
    }

    public function createSandboxDraft(string $type, #[SensitiveParameter] array $payload, string $expectedSubdomain): array
    {
        $config = $this->configuration();
        if (! self::sandboxDraftsAllowed($config->subdomain) || $config->subdomain !== $expectedSubdomain
            || ! isset(InvoiceXpressSandboxDrafts::DocumentTypes[$type])) {
            throw new InvoiceXpressException(__('Drafts can only be created in the configured sandbox.'));
        }

        return $this->send($config, 'POST', "/{$type}s.json", [], $payload, [], requiresCatalogValidation: false);
    }

    public function saveContact(string $subdomain, ?int $contactId, #[SensitiveParameter] array $contact): array
    {
        abort_unless(InvoiceXpressIssueRepository::ownerCanIssue(), 403);
        $config = $this->configuration();
        if ($subdomain !== $config->subdomain || ($contactId !== null && $contactId < 1)) {
            throw new InvoiceXpressException(__('The InvoiceXpress account or customer has changed.'));
        }

        return $this->send(
            $config,
            $contactId === null ? 'POST' : 'PUT',
            $contactId === null ? '/clients.json' : "/clients/{$contactId}.json",
            [],
            ['client' => $contact],
            [],
            requiresCatalogValidation: false,
            acceptsEmptyBody: $contactId !== null,
        );
    }

    public function configuration(): InvoiceXpressConfig
    {
        return InvoiceXpressConfig::current()
            ?? throw new InvoiceXpressException(__('Set up the connection to InvoiceXpress first.'));
    }

    /**
     * Returns the decoded official JSON envelope. On failure only the local message and optional
     * HTTP status are exposed.
     */
    public function request(
        string $method,
        string $relativePath,
        #[SensitiveParameter] array $query = [],
        #[SensitiveParameter] array $payload = [],
        #[SensitiveParameter] array $headers = [],
        ?string $expectedSubdomain = null,
    ): array {
        $config = $this->configuration();

        if ($expectedSubdomain !== null && $config->subdomain !== $expectedSubdomain) {
            throw new InvoiceXpressException(__('The InvoiceXpress account has changed.'));
        }

        return $this->send($config, $method, $relativePath, $query, $payload, $headers);
    }

    /** @return array{subdomain: string, invoices: list<mixed>, page: int, totalPages: int} */
    public function listInvoices(string $from, string $to, int $page = 1, bool $archived = false): array
    {
        $start = $this->date($from);
        $end = $this->date($to);

        if ($start === null || $end === null || $end < $start || $start->diff($end)->days > 365) {
            throw ValidationException::withMessages(['to' => __('Choose a valid date range of up to 366 days.')]);
        }

        if ($page < 1 || $page > self::MAX_PAGES) {
            throw ValidationException::withMessages(['page' => __('Narrow the date range to view more documents.')]);
        }

        $config = $this->configuration();
        // Use the default status scope: the list returns final documents, but final is not a documented filter value.
        $result = $this->send($config, 'GET', '/invoices.json', [
            'type[]' => ['Invoice', 'InvoiceReceipt', 'SimplifiedInvoice', 'CreditNote', 'DebitNote'],
            'date[from]' => $from,
            'date[to]' => $to,
            'page' => $page,
            'per_page' => self::PER_PAGE,
            ...($archived ? ['archived' => 'true'] : []),
        ], [], []);

        $invoices = $result['invoices'] ?? null;
        $pagination = $result['pagination'] ?? null;
        $currentPage = $pagination['current_page'] ?? null;
        $totalPages = $pagination['total_pages'] ?? null;

        if (! is_array($invoices) || ! array_is_list($invoices) || count($invoices) > self::PER_PAGE
            || ! is_int($totalPages) || $totalPages < 0
            || ($currentPage !== $page && ! ($page === 1 && $currentPage === 0 && $totalPages === 0 && $invoices === []))) {
            throw new InvoiceXpressException(__('InvoiceXpress returned an invalid document list.'));
        }

        return ['subdomain' => $config->subdomain, 'invoices' => $invoices, 'page' => $page, 'totalPages' => $totalPages];
    }

    private function send(
        #[SensitiveParameter] InvoiceXpressConfig $config,
        string $method,
        string $relativePath,
        #[SensitiveParameter] array $query,
        #[SensitiveParameter] array $payload,
        #[SensitiveParameter] array $headers,
        bool $requiresCatalogValidation = true,
        bool $acceptsEmptyBody = false,
    ): array {
        $method = strtoupper($method);

        if (! in_array($method, ['GET', 'POST', 'PUT'], true)
            || ! preg_match('/\A\/?(?:[a-z0-9_-]+\/)*[a-z0-9_-]+\.json\z/i', $relativePath)
            || ! preg_match(InvoiceXpressConfig::SUBDOMAIN_PATTERN, $config->subdomain)
            || ($method === 'GET' && $payload !== [])) {
            throw new InvoiceXpressException(__('The InvoiceXpress request is invalid.'));
        }

        if ($method !== 'GET' && (CurrentAccount::readOnly() || AccountantAccess::isReviewOnly())) {
            throw new AccountIsReadOnly;
        }

        if ($method !== 'GET' && $requiresCatalogValidation && ! self::catalogValidatedFor($config->subdomain)) {
            throw new InvoiceXpressException(__('Issuance is pending confirmation that the API preserves the InvoiceXpress catalog.'));
        }

        foreach ($headers as $name => $value) {
            if (! in_array(strtolower((string) $name), ['accept-language', 'idempotency-key', 'if-match', 'if-none-match'], true)
                || ! is_string($value) || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new InvoiceXpressException(__('The InvoiceXpress request is invalid.'));
            }
        }

        $queryString = $this->queryString($query);

        try {
            $url = 'https://'.$config->subdomain.'.app.invoicexpress.com/'.ltrim($relativePath, '/');
            $queryString[] = 'api_key='.rawurlencode($config->api_key);
            $response = Http::acceptJson()
                ->asJson()
                ->withHeaders($headers)
                ->withoutRedirecting()
                ->connectTimeout(3)
                ->timeout(10)
                ->send($method, $url, [
                    'query' => implode('&', $queryString),
                    ...($payload === [] ? [] : ['json' => $payload]),
                ]);
        } catch (Throwable) {
            throw new InvoiceXpressException(__('Could not communicate with InvoiceXpress.'));
        }

        if (! $response->successful()) {
            $status = $response->status();

            throw new InvoiceXpressException(match ($status) {
                401, 403 => __('InvoiceXpress refused the connection. Check the account and API key.'),
                429 => __('InvoiceXpress reached its request limit. Try again later.'),
                default => __('InvoiceXpress refused the request or could not complete it.'),
            }, $status);
        }

        if ($response->noContent() || ($acceptsEmptyBody && trim($response->body()) === '')) {
            return [];
        }

        $decoded = $response->json();

        if (! is_array($decoded) || array_is_list($decoded) || ! empty($decoded['errors'])) {
            throw new InvoiceXpressException(__('InvoiceXpress returned an invalid response.'));
        }

        return $decoded;
    }

    /** @return list<string> */
    private function queryString(#[SensitiveParameter] array $query): array
    {
        $pairs = [];

        foreach ($query as $key => $values) {
            if (! is_string($key) || ! preg_match('/\A[a-z0-9_]+(?:\[[a-z0-9_]*\])?\z/i', $key)
                || preg_match('/\Aapi_key(?:\[|\z)/i', $key)
                || (is_array($values) && (! str_ends_with($key, '[]') || ! array_is_list($values)))) {
                throw new InvoiceXpressException(__('The InvoiceXpress request is invalid.'));
            }

            foreach ((array) $values as $value) {
                if (! is_scalar($value)) {
                    throw new InvoiceXpressException(__('The InvoiceXpress request is invalid.'));
                }

                $pairs[] = rawurlencode($key).'='.rawurlencode(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
            }
        }

        return $pairs;
    }

    private function date(string $value): ?DateTimeImmutable
    {
        if (! preg_match('/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}\z/', $value)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }
}
