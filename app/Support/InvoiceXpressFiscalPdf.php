<?php

namespace App\Support;

use App\Exceptions\InvoiceXpressException;
use App\Models\InvoiceXpressConfig;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

final class InvoiceXpressFiscalPdf
{
    private const DOWNLOAD_HOST = 'invoicexpress-downloads.s3.amazonaws.com';

    public function retrieve(#[SensitiveParameter] InvoiceXpressConfig $config, int $providerId): ?string
    {
        if ($providerId < 1 || ! preg_match(InvoiceXpressConfig::SUBDOMAIN_PATTERN, $config->subdomain)) {
            throw new InvoiceXpressException(__('The link to the InvoiceXpress document is invalid.'));
        }

        $host = strtolower($config->subdomain).'.app.invoicexpress.com';
        try {
            $apiKey = $config->api_key;
            $response = $this->get("https://{$host}/api/pdf/{$providerId}.json", 'application/json', [
                'second_copy' => 'false',
                'api_key' => $apiKey,
            ]);

            if ($response->status() === 202) {
                $response->close();

                return null;
            }

            $body = $this->read($response, 'application/json', 32768);
            $url = json_decode($body, true, 16, JSON_THROW_ON_ERROR)['output']['pdfUrl'] ?? null;
        } catch (Throwable) {
            throw new InvoiceXpressException(__('Could not get the link to the official PDF.'));
        }

        if (! is_string($url) || ! $this->isTrustedUrl($url, $host, $apiKey)) {
            throw new InvoiceXpressException(__('InvoiceXpress returned an invalid PDF link.'));
        }

        try {
            $bytes = $this->read($this->get($url, 'application/pdf'), 'application/pdf', FiscalDocumentPdfs::MAX_BYTES);
        } catch (Throwable) {
            throw new InvoiceXpressException(__('Could not download a valid PDF from InvoiceXpress.'));
        }

        if (! FiscalDocumentPdfs::isPdf($bytes)) {
            throw new InvoiceXpressException(__('InvoiceXpress returned an invalid PDF.'));
        }

        return $bytes;
    }

    private function get(#[SensitiveParameter] string $url, string $accept, #[SensitiveParameter] array $query = []): Response
    {
        $request = Http::withoutGlobalConfiguration(fn () => Http::accept($accept));

        return $request
            ->withHeaders(['Accept-Encoding' => 'identity'])
            ->withoutRedirecting()
            ->connectTimeout(3)
            ->timeout(10)
            ->withOptions([
                'stream' => true,
                'read_timeout' => 3,
                'decode_content' => false,
                'debug' => false,
                'cookies' => false,
            ])
            ->get($url, $query === [] ? null : $query);
    }

    private function read(#[SensitiveParameter] Response $response, string $mime, int $maxBytes): string
    {
        $stream = $response->toPsrResponse()->getBody();

        try {
            $type = strtolower(trim(explode(';', $response->header('Content-Type'), 2)[0]));
            $length = $response->header('Content-Length');

            if (! $response->ok() || $type !== $mime
                || ($length !== '' && (! ctype_digit($length) || (int) $length > $maxBytes))) {
                throw new InvoiceXpressException(__('The InvoiceXpress response is invalid.'));
            }

            $bytes = Utils::copyToString($stream, $maxBytes + 1);
            if (! $stream->eof() || strlen($bytes) > $maxBytes || ($length !== '' && strlen($bytes) !== (int) $length)) {
                throw new InvoiceXpressException(__('The InvoiceXpress response is incomplete or exceeds the allowed size.'));
            }

            return $bytes;
        } finally {
            $response->close();
        }
    }

    private function isTrustedUrl(#[SensitiveParameter] string $url, string $accountHost, #[SensitiveParameter] string $apiKey): bool
    {
        if (strlen($url) > 8192 || preg_match('~[\x00-\x20\x7f\\\\#]~', $url)
            || ! preg_match('~\Ahttps://([a-z0-9.-]+)/[^?]+\.pdf(?:\?[^#]*)?\z~i', $url, $matches)) {
            return false;
        }

        if (! in_array(strtolower($matches[1]), [$accountHost, self::DOWNLOAD_HOST], true)) {
            return false;
        }

        $decoded = rawurldecode(substr($url, strlen($matches[1]) + strlen('https://')));
        $query = rawurldecode((string) parse_url($url, PHP_URL_QUERY));

        return ($apiKey === '' || ! str_contains($decoded, $apiKey))
            && ! preg_match('/(?:^|[&;])api_key(?:=|\[|$)/i', $query);
    }
}
