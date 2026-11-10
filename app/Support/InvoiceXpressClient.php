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

    public function configuration(): InvoiceXpressConfig
    {
        return InvoiceXpressConfig::current()
            ?? throw new InvoiceXpressException('Configura a ligação ao InvoiceXpress primeiro.');
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
            throw new InvoiceXpressException('A conta InvoiceXpress mudou.');
        }

        return $this->send($config, $method, $relativePath, $query, $payload, $headers);
    }

    /** @return array{subdomain: string, invoices: list<mixed>, page: int, totalPages: int} */
    public function listInvoices(string $from, string $to, int $page = 1, bool $archived = false): array
    {
        $start = $this->date($from);
        $end = $this->date($to);

        if ($start === null || $end === null || $end < $start || $start->diff($end)->days > 365) {
            throw ValidationException::withMessages(['to' => 'Escolhe um intervalo válido de até 366 dias.']);
        }

        if ($page < 1 || $page > self::MAX_PAGES) {
            throw ValidationException::withMessages(['page' => 'Reduz o intervalo de datas para consultar mais documentos.']);
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
            throw new InvoiceXpressException('O InvoiceXpress devolveu uma lista de documentos inválida.');
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
    ): array {
        $method = strtoupper($method);

        if (! in_array($method, ['GET', 'POST', 'PUT'], true)
            || ! preg_match('/\A\/?(?:[a-z0-9_-]+\/)*[a-z0-9_-]+\.json\z/i', $relativePath)
            || ! preg_match(InvoiceXpressConfig::SUBDOMAIN_PATTERN, $config->subdomain)
            || ($method === 'GET' && $payload !== [])) {
            throw new InvoiceXpressException('O pedido ao InvoiceXpress é inválido.');
        }

        if ($method !== 'GET' && (CurrentAccount::readOnly() || AccountantAccess::isReviewOnly())) {
            throw new AccountIsReadOnly;
        }

        if ($method !== 'GET' && ! self::catalogValidatedFor($config->subdomain)) {
            throw new InvoiceXpressException('A emissão aguarda a validação de que a API preserva o catálogo do InvoiceXpress.');
        }

        foreach ($headers as $name => $value) {
            if (! in_array(strtolower((string) $name), ['accept-language', 'idempotency-key', 'if-match', 'if-none-match'], true)
                || ! is_string($value) || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new InvoiceXpressException('O pedido ao InvoiceXpress é inválido.');
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
            throw new InvoiceXpressException('Não foi possível comunicar com o InvoiceXpress.');
        }

        if (! $response->successful()) {
            $status = $response->status();

            throw new InvoiceXpressException(match ($status) {
                401, 403 => 'O InvoiceXpress recusou a ligação. Confirma a conta e a chave de API.',
                429 => 'O InvoiceXpress atingiu o limite de pedidos. Tenta novamente mais tarde.',
                default => 'O InvoiceXpress recusou ou não conseguiu concluir o pedido.',
            }, $status);
        }

        if ($response->noContent()) {
            return [];
        }

        $decoded = $response->json();

        if (! is_array($decoded) || array_is_list($decoded) || ! empty($decoded['errors'])) {
            throw new InvoiceXpressException('O InvoiceXpress devolveu uma resposta inválida.');
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
                throw new InvoiceXpressException('O pedido ao InvoiceXpress é inválido.');
            }

            foreach ((array) $values as $value) {
                if (! is_scalar($value)) {
                    throw new InvoiceXpressException('O pedido ao InvoiceXpress é inválido.');
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
