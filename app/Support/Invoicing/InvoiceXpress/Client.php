<?php

namespace App\Support\Invoicing\InvoiceXpress;

use App\Exceptions\AccountIsReadOnly;
use App\Exceptions\InvoicingProviderException;
use App\Support\AccountantAccess;
use App\Support\CurrentAccount;
use App\Support\FiscalDocumentPdfs;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

/**
 * The HTTP side of InvoiceXpress. It only ever talks to the account's own host, over HTTPS and
 * without following redirects, and it never lets the API key reach a message, a log or a URL that
 * leaves this class.
 */
final class Client
{
    public const string SUBDOMAIN_PATTERN = '/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/';

    /** The buckets InvoiceXpress serves finished PDFs from; a link to anywhere else is refused. */
    private const array DOWNLOAD_HOSTS = [
        'invoicexpress-documents-pdfs.s3.eu-west-1.amazonaws.com',
        'invoicexpress-downloads.s3.amazonaws.com',
    ];

    public function __construct(
        private readonly string $subdomain,
        #[SensitiveParameter] private readonly string $apiKey,
    ) {}

    public function host(): string
    {
        return "{$this->subdomain}.app.invoicexpress.com";
    }

    /**
     * @param  array<string, scalar|list<scalar>>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->send('GET', $path, $query, []);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function post(string $path, #[SensitiveParameter] array $payload): array
    {
        return $this->send('POST', $path, [], $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function put(string $path, #[SensitiveParameter] array $payload): array
    {
        return $this->send('PUT', $path, [], $payload, acceptsEmptyBody: true);
    }

    /** The official PDF of a document, or null while InvoiceXpress is still producing it. */
    public function pdf(string $documentId): ?string
    {
        if (! ctype_digit($documentId)) {
            throw new InvoicingProviderException(__('The link to the InvoiceXpress document is invalid.'));
        }

        try {
            $response = $this->stream("https://{$this->host()}/api/pdf/{$documentId}.json", 'application/json', [
                'second_copy' => 'false',
                'api_key' => $this->apiKey,
            ]);

            if ($response->status() === 202) {
                $response->close();

                return null;
            }

            $url = json_decode($this->read($response, 'application/json', 32768), true, 16, JSON_THROW_ON_ERROR)['output']['pdfUrl'] ?? null;
        } catch (Throwable) {
            throw new InvoicingProviderException(__('Could not get the link to the official PDF.'));
        }

        if (! is_string($url) || ! $this->isTrustedDownload($url)) {
            throw new InvoicingProviderException(__('InvoiceXpress returned an invalid PDF link.'));
        }

        try {
            $bytes = $this->read($this->stream($url, 'application/pdf'), 'application/pdf', FiscalDocumentPdfs::MAX_BYTES);
        } catch (Throwable) {
            throw new InvoicingProviderException(__('Could not download a valid PDF from InvoiceXpress.'));
        }

        if (! FiscalDocumentPdfs::isPdf($bytes)) {
            throw new InvoicingProviderException(__('InvoiceXpress returned an invalid PDF.'));
        }

        return $bytes;
    }

    /**
     * @param  array<string, scalar|list<scalar>>  $query
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $query, #[SensitiveParameter] array $payload, bool $acceptsEmptyBody = false): array
    {
        if (! preg_match('/\A\/?(?:[a-z0-9_-]+\/)*[a-z0-9_-]+\.json\z/i', $path) || ! preg_match(self::SUBDOMAIN_PATTERN, $this->subdomain)) {
            throw new InvoicingProviderException(__('The InvoiceXpress request is invalid.'));
        }

        if ($method !== 'GET' && (CurrentAccount::readOnly() || AccountantAccess::isReviewOnly())) {
            throw new AccountIsReadOnly;
        }

        $pairs = $this->queryString($query);
        $pairs[] = 'api_key='.rawurlencode($this->apiKey);

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withoutRedirecting()
                ->connectTimeout(3)
                ->timeout(10)
                ->send($method, "https://{$this->host()}/".ltrim($path, '/'), [
                    'query' => implode('&', $pairs),
                    ...($payload === [] ? [] : ['json' => $payload]),
                ]);
        } catch (Throwable) {
            throw new InvoicingProviderException(__('Could not communicate with InvoiceXpress.'));
        }

        if (! $response->successful()) {
            throw new InvoicingProviderException($this->failure($response), $response->status());
        }

        if ($response->noContent() || ($acceptsEmptyBody && trim($response->body()) === '')) {
            return [];
        }

        $decoded = $response->json();

        if (! is_array($decoded) || array_is_list($decoded) || ! empty($decoded['errors'])) {
            throw new InvoicingProviderException(__('InvoiceXpress returned an invalid response.'));
        }

        return $decoded;
    }

    private function failure(Response $response): string
    {
        return match ($response->status()) {
            401, 403 => __('InvoiceXpress refused the connection. Check the account and API key.'),
            404 => __('InvoiceXpress no longer has this record.'),
            422 => __('InvoiceXpress refused the document: :reason', ['reason' => $this->reason($response)]),
            429 => __('InvoiceXpress reached its request limit. Try again later.'),
            default => __('InvoiceXpress refused the request or could not complete it.'),
        };
    }

    /** The first thing InvoiceXpress says is wrong, cut short and without anything that is not plain text. */
    private function reason(Response $response): string
    {
        $errors = $response->json('errors');
        $first = is_array($errors) ? ($errors[0]['error'] ?? $errors[0] ?? null) : null;
        $text = is_string($first) ? trim((string) preg_replace('/[^\PC\s]|\s+/u', ' ', strip_tags($first))) : '';

        return $text === '' ? __('review the details and try again.') : mb_strimwidth($text, 0, 200, '…');
    }

    /**
     * @param  array<string, scalar|list<scalar>>  $query
     * @return list<string>
     */
    private function queryString(array $query): array
    {
        $pairs = [];

        foreach ($query as $key => $values) {
            if (! is_string($key) || ! preg_match('/\A[a-z0-9_]+(?:\[[a-z0-9_]*\])?\z/i', $key)
                || preg_match('/\Aapi_key(?:\[|\z)/i', $key)
                || (is_array($values) && (! str_ends_with($key, '[]') || ! array_is_list($values)))) {
                throw new InvoicingProviderException(__('The InvoiceXpress request is invalid.'));
            }

            foreach ((array) $values as $value) {
                if (! is_scalar($value)) {
                    throw new InvoicingProviderException(__('The InvoiceXpress request is invalid.'));
                }

                $pairs[] = rawurlencode($key).'='.rawurlencode(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
            }
        }

        return $pairs;
    }

    /** @param array<string, string> $query */
    private function stream(#[SensitiveParameter] string $url, string $accept, #[SensitiveParameter] array $query = []): Response
    {
        return Http::withoutGlobalConfiguration(fn () => Http::accept($accept))
            ->withHeaders(['Accept-Encoding' => 'identity'])
            ->withoutRedirecting()
            ->connectTimeout(3)
            ->timeout(10)
            ->withOptions(['stream' => true, 'read_timeout' => 3, 'decode_content' => false, 'debug' => false, 'cookies' => false])
            ->get($url, $query === [] ? null : $query);
    }

    private function read(Response $response, string $mime, int $maxBytes): string
    {
        $stream = $response->toPsrResponse()->getBody();

        try {
            $type = strtolower(trim(explode(';', $response->header('Content-Type'), 2)[0]));
            $length = $response->header('Content-Length');

            if (! $response->ok() || $type !== $mime || ($length !== '' && (! ctype_digit($length) || (int) $length > $maxBytes))) {
                throw new InvoicingProviderException(__('The InvoiceXpress response is invalid.'));
            }

            $bytes = Utils::copyToString($stream, $maxBytes + 1);

            if (! $stream->eof() || strlen($bytes) > $maxBytes || ($length !== '' && strlen($bytes) !== (int) $length)) {
                throw new InvoicingProviderException(__('The InvoiceXpress response is incomplete or exceeds the allowed size.'));
            }

            return $bytes;
        } finally {
            $response->close();
        }
    }

    /** A PDF link is only followed to the account's host or one of InvoiceXpress's download buckets, and never with the key in it. */
    private function isTrustedDownload(#[SensitiveParameter] string $url): bool
    {
        if (strlen($url) > 8192 || preg_match('~[\x00-\x20\x7f\\\\#]~', $url)
            || ! preg_match('~\Ahttps://([a-z0-9.-]+)/[^?]+\.pdf(?:\?[^#]*)?\z~i', $url, $matches)
            || ! in_array(strtolower($matches[1]), [$this->host(), ...self::DOWNLOAD_HOSTS], true)) {
            return false;
        }

        $decoded = rawurldecode(substr($url, strlen($matches[1]) + strlen('https://')));
        $query = rawurldecode((string) parse_url($url, PHP_URL_QUERY));

        return ! str_contains($decoded, $this->apiKey) && ! preg_match('/(?:^|[&;])api_key(?:=|\[|$)/i', $query);
    }
}
