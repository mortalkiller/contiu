<?php

namespace App\Support\Invoicing\InvoiceXpress;

use App\Enums\FiscalDocumentType;
use App\Exceptions\InvoicingProviderException;
use App\Models\Article;
use App\Models\Client as LocalClient;
use App\Models\FiscalDocument;
use App\Models\IntegrationConnection;
use App\Support\FinanceSettings;
use App\Support\Invoicing\InvoicingCapabilities;
use App\Support\Invoicing\InvoicingCatalog;
use App\Support\Invoicing\InvoicingProvider;
use App\Support\Invoicing\RemoteDocument;
use App\Support\Invoicing\RemotePage;
use App\Support\Invoicing\RemoteReferences;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/** InvoiceXpress as the account's invoicing provider. */
final class InvoiceXpressProvider implements InvoicingProvider
{
    private const int PER_PAGE = 30;

    private readonly Client $client;

    private ?InvoicingCatalog $catalog = null;

    public function __construct(private readonly IntegrationConnection $connection)
    {
        $this->client = new Client(
            (string) ($connection->credentials['subdomain'] ?? ''),
            (string) ($connection->credentials['api_key'] ?? ''),
        );
    }

    public static function key(): string
    {
        return 'invoicexpress';
    }

    public static function label(): string
    {
        return 'InvoiceXpress';
    }

    public static function credentialFields(): array
    {
        return [
            'subdomain' => [
                'label' => __('Account'),
                'help' => __('The start of the address you use to sign in to InvoiceXpress.'),
                'suffix' => '.app.invoicexpress.com',
            ],
            'api_key' => [
                'label' => __('API key'),
                'help' => __('In InvoiceXpress, under Account › Integrations › API.'),
                'secret' => true,
            ],
        ];
    }

    public static function credentials(#[SensitiveParameter] array $input): array
    {
        $subdomain = strtolower(trim((string) ($input['subdomain'] ?? '')));
        $apiKey = trim((string) ($input['api_key'] ?? ''));

        if (! preg_match(Client::SUBDOMAIN_PATTERN, $subdomain)) {
            throw ValidationException::withMessages(['subdomain' => __('Enter only the account name, without the rest of the address.')]);
        }

        if ($apiKey === '' || strlen($apiKey) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $apiKey)) {
            throw ValidationException::withMessages(['api_key' => __('Enter the API key exactly as InvoiceXpress shows it.')]);
        }

        return ['subdomain' => $subdomain, 'api_key' => $apiKey];
    }

    public function capabilities(): InvoicingCapabilities
    {
        return new InvoicingCapabilities(FiscalDocumentType::cases());
    }

    public function test(): void
    {
        $this->client->get('/sequences.json');
    }

    /**
     * Cached as plain arrays: the cache refuses to rebuild objects, so the catalogue itself would
     * come back unusable from any store that serialises what it keeps.
     */
    public function catalog(): InvoicingCatalog
    {
        if ($this->catalog !== null) {
            return $this->catalog;
        }

        $cached = Cache::get($this->catalogKey());

        if (! is_array($cached) || ! is_array($cached['series'] ?? null) || ! is_array($cached['taxes'] ?? null)) {
            $cached = ['series' => $this->series(), 'taxes' => $this->taxes()];
            Cache::put($this->catalogKey(), $cached, now()->addMinutes(10));
        }

        return $this->catalog = new InvoicingCatalog($cached['series'], $cached['taxes']);
    }

    public function refreshCatalog(): InvoicingCatalog
    {
        Cache::forget($this->catalogKey());
        $this->catalog = null;

        return $this->catalog();
    }

    public function saveDraft(FiscalDocument $document, RemoteReferences $references): RemoteDocument
    {
        $resource = Mapper::resource($document->type);
        $payload = Mapper::payload(
            $document->loadMissing('lines.article'),
            $this->catalog(),
            $references,
            $this->connection->setting('series'),
            app(FinanceSettings::class)->vatRegion()?->value,
        );

        if ($references->document === null) {
            return Mapper::document($this->client->post("/{$resource}.json", $payload));
        }

        $this->client->put("/{$resource}/{$references->document}.json", $payload);

        return $this->find($resource, $references->document);
    }

    public function issue(FiscalDocument $document, string $remoteId): RemoteDocument
    {
        return $this->changeState($document->type, $remoteId, ['state' => 'finalized']);
    }

    public function cancel(FiscalDocument $document, string $remoteId, string $reason): RemoteDocument
    {
        return $this->changeState($document->type, $remoteId, ['state' => 'canceled', 'message' => $reason]);
    }

    public function discard(FiscalDocument $document, string $remoteId): void
    {
        $this->assertId($remoteId);
        $this->client->put('/'.Mapper::resource($document->type)."/{$remoteId}/change-state.json", [Mapper::key($document->type) => ['state' => 'deleted']]);
    }

    public function settle(FiscalDocument $receipt, string $invoiceRemoteId): RemoteDocument
    {
        $this->assertId($invoiceRemoteId);

        return Mapper::document($this->client->post(
            "/documents/{$invoiceRemoteId}/partial_payments.json",
            Mapper::payment($receipt, $this->catalog(), $this->connection->setting('series')),
        ));
    }

    public function find(string $remoteType, string $remoteId): RemoteDocument
    {
        $this->assertId($remoteId);

        return Mapper::document($this->client->get("/{$remoteType}/{$remoteId}.json"));
    }

    public function documents(CarbonInterface $from, CarbonInterface $until, int $page = 1): RemotePage
    {
        $response = $this->client->get('/invoices.json', [
            'type[]' => Mapper::invoiceTypeNames(),
            'date[from]' => $from->format('d/m/Y'),
            'date[to]' => $until->format('d/m/Y'),
            'page' => max(1, $page),
            'per_page' => self::PER_PAGE,
        ]);

        $rows = $response['invoices'] ?? null;
        $totalPages = $response['pagination']['total_pages'] ?? null;

        if (! is_array($rows) || ! array_is_list($rows) || ! is_int($totalPages)) {
            throw new InvoicingProviderException(__('InvoiceXpress returned an invalid document list.'));
        }

        return new RemotePage(
            array_map(Mapper::document(...), array_values(array_filter($rows, is_array(...)))),
            $page < $totalPages,
        );
    }

    public function pdf(string $remoteType, string $remoteId): ?string
    {
        return $this->client->pdf($remoteId);
    }

    public function saveClient(LocalClient $client, ?string $remoteId = null): string
    {
        $address = $client->structuredAddress;
        $payload = ['client' => array_filter([
            'name' => $client->name,
            'code' => "CONTIU-{$client->getKey()}",
            'email' => $client->email,
            'phone' => $client->phone,
            'address' => $address?->street,
            'city' => $address?->locality,
            'postal_code' => $address?->postal_code,
            'country' => 'Portugal',
            'fiscal_id' => $client->vat_number,
        ], filled(...))];

        if ($remoteId !== null) {
            $this->assertId($remoteId);
            $this->client->put("/clients/{$remoteId}.json", $payload);

            return $remoteId;
        }

        return $this->createdId($this->client->post('/clients.json', $payload), 'client');
    }

    public function saveArticle(Article $article, ?string $remoteId = null): string
    {
        $tax = $this->catalog()->taxName($article->vat_basis_points ?? 0, app(FinanceSettings::class)->vatRegion()?->value);
        $payload = ['item' => array_filter([
            'name' => $article->name,
            'description' => $article->name,
            'unit_price' => (string) Money::toEuros($article->unit_price),
            'unit' => Mapper::unit($article->unit),
            'tax' => $tax === null ? null : ['name' => $tax],
        ], filled(...))];

        if ($remoteId !== null) {
            $this->assertId($remoteId);
            $this->client->put("/items/{$remoteId}.json", $payload);

            return $remoteId;
        }

        return $this->createdId($this->client->post('/items.json', $payload), 'item');
    }

    public function documentUrl(string $remoteType, string $remoteId): ?string
    {
        return ctype_digit($remoteId) && preg_match('/\A[a-z_]+\z/', $remoteType)
            ? "https://{$this->client->host()}/{$remoteType}/{$remoteId}"
            : null;
    }

    /** @param array{state: string, message?: string} $state */
    private function changeState(FiscalDocumentType $type, string $remoteId, array $state): RemoteDocument
    {
        $this->assertId($remoteId);
        $resource = Mapper::resource($type);
        $this->client->put("/{$resource}/{$remoteId}/change-state.json", [Mapper::key($type) => $state]);

        return $this->find($resource, $remoteId);
    }

    /** @return list<array{id: string, name: string, default: bool, next: array<string, int>, ids: array<string, string>}> */
    private function series(): array
    {
        $series = [];

        foreach ((array) ($this->client->get('/sequences.json')['sequences'] ?? []) as $sequence) {
            if (! is_array($sequence) || ! is_int($sequence['id'] ?? null) || ! is_string($sequence['serie'] ?? null)) {
                continue;
            }

            $next = [];
            $ids = [];

            foreach (FiscalDocumentType::cases() as $type) {
                $kind = Mapper::key($type);

                if (is_int($sequence["current_{$kind}_sequence_id"] ?? null)) {
                    $ids[$type->value] = (string) $sequence["current_{$kind}_sequence_id"];
                    $next[$type->value] = (int) ($sequence["current_{$kind}_number"] ?? 0) + 1;
                }
            }

            $series[] = [
                'id' => (string) $sequence['id'],
                'name' => $sequence['serie'],
                'default' => (bool) ($sequence['default_sequence'] ?? false),
                'next' => $next,
                'ids' => $ids,
            ];
        }

        return $series;
    }

    /** @return list<array{name: string, basisPoints: int, region: ?string}> */
    private function taxes(): array
    {
        $taxes = [];

        foreach ((array) ($this->client->get('/taxes.json')['taxes'] ?? []) as $tax) {
            if (is_array($tax) && is_string($tax['name'] ?? null) && is_numeric($tax['value'] ?? null)) {
                $taxes[] = [
                    'name' => $tax['name'],
                    'basisPoints' => (int) round((float) $tax['value'] * 100),
                    'region' => is_string($tax['region'] ?? null) && $tax['region'] !== '' ? $tax['region'] : null,
                ];
            }
        }

        return $taxes;
    }

    /** @param array<string, mixed> $response */
    private function createdId(array $response, string $key): string
    {
        $id = $response[$key]['id'] ?? null;

        if (! is_int($id) || $id < 1) {
            throw new InvoicingProviderException(__('InvoiceXpress returned an invalid response.'));
        }

        return (string) $id;
    }

    /** Remote ids go into URLs, so they are only ever digits. */
    private function assertId(string $remoteId): void
    {
        if (! ctype_digit($remoteId) || strlen($remoteId) > 18) {
            throw new InvoicingProviderException(__('The InvoiceXpress request is invalid.'));
        }
    }

    private function catalogKey(): string
    {
        // Keyed by the account at the provider, so reconnecting to another one never reads the old series.
        return "invoicing:catalog:v2:{$this->connection->getKey()}:".($this->connection->credentials['subdomain'] ?? '');
    }
}
