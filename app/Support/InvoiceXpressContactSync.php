<?php

namespace App\Support;

use App\Enums\Language;
use App\Exceptions\InvoiceXpressException;
use App\Models\Account;
use App\Models\Client;
use App\Models\InvoiceXpressConfig;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class InvoiceXpressContactSync
{
    public function __construct(
        private readonly InvoiceXpressClient $api,
        private readonly InvoiceXpressCatalog $catalog,
    ) {}

    public static function available(): bool
    {
        return InvoiceXpressIssueRepository::ownerCanIssue() && InvoiceXpressConfig::current() !== null;
    }

    public function pull(string $nif, ?int $expectedClientId = null): Client
    {
        abort_unless(self::available(), 403);
        $nif = self::nif($nif, 'PT') ?? throw self::invalidNif();
        $subdomain = $this->api->configuration()->subdomain;
        $remote = $this->remote($subdomain, $nif)
            ?? throw ValidationException::withMessages(['nif' => __('This tax ID was not found in InvoiceXpress.')]);
        $details = $this->contactFields($remote);
        $language = match ($remote['language'] ?? null) {
            'pt' => Language::PortuguesePortugal,
            'en' => Language::EnglishUnitedStates,
            default => null,
        };
        if ($language !== null) {
            $details['language'] = $language->value;
        }

        return DB::transaction(function () use ($nif, $details, $subdomain, $expectedClientId): Client {
            Account::query()->lockForUpdate()->findOrFail(CurrentAccount::id());
            abort_unless(self::available(), 403);
            if ($this->api->configuration()->subdomain !== $subdomain) {
                throw new InvoiceXpressException(__('The InvoiceXpress connection has changed. Run the lookup again.'));
            }

            $client = $this->local($nif, lock: true);
            if ($expectedClientId !== null && $client?->id !== $expectedClientId) {
                throw ValidationException::withMessages(['nif' => __('The tax ID on the record has changed. Refresh the page before syncing.')]);
            }

            if ($client === null) {
                Gate::authorize('create', Client::class);
                $client = new Client(['vat_number' => $nif, 'fiscal_country_code' => 'PT']);
            } else {
                Gate::authorize('update', $client);
            }

            $client->fill($details)->save();

            return $client;
        }, attempts: 3);
    }

    public function push(int $clientId): int
    {
        abort_unless(self::available(), 403);
        $client = Client::query()->findOrFail($clientId);
        Gate::authorize('update', $client);
        $nif = self::nif($client->vat_number, $client->fiscal_country_code) ?? throw self::invalidNif();
        $subdomain = $this->api->configuration()->subdomain;
        $lock = Cache::lock('invoicexpress-contact:'.hash('sha256', $subdomain.'|'.$nif), 360);

        try {
            return $lock->block(3, function () use ($clientId, $nif, $subdomain): int {
                $client = $this->local($nif);
                if ($client?->id !== $clientId) {
                    throw ValidationException::withMessages(['nif' => __('The tax ID on the record has changed. Refresh the page before syncing.')]);
                }

                Gate::authorize('update', $client);
                $details = $this->contactFields($client->getAttributes(), forExport: true);
                $details['language'] = match ($client->preferredLanguage()) {
                    Language::PortuguesePortugal => 'pt',
                    Language::EnglishUnitedStates => 'en',
                };
                $remote = $this->remote($subdomain, $nif);
                $id = $remote['id'] ?? null;
                $existingDetails = $remote === null ? [] : $this->remoteContactFields($remote);

                if ($id !== null && array_diff_assoc($details, $existingDetails) === []) {
                    return $id;
                }

                $details = [...$existingDetails, ...$details];
                $response = $this->api->saveContact($subdomain, $id, [
                    ...$details,
                    'code' => filled($remote['code'] ?? null) ? $remote['code'] : 'CONTIU-PT-'.$nif,
                    'country' => 'Portugal',
                    'fiscal_id' => $nif,
                ]);
                $savedId = $id ?? InvoiceXpressIssueTerms::positiveId($response['client']['id'] ?? null);
                $saved = $savedId === null ? [] : $this->readRemote($subdomain, $savedId, $nif);
                if ($savedId === null || array_diff_assoc($details, $this->remoteContactFields($saved)) !== []) {
                    throw new InvoiceXpressException(__('InvoiceXpress did not confirm the customer record for this tax ID. Check the record before trying again.'));
                }

                return $savedId;
            });
        } catch (LockTimeoutException) {
            throw new InvoiceXpressException(__('A sync is already in progress for this tax ID.'));
        }
    }

    private function local(string $nif, bool $lock = false): ?Client
    {
        $matches = Client::query()->whereNotNull('vat_number')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->cursor()->filter(fn (Client $client): bool => self::nif($client->vat_number, $client->fiscal_country_code) === $nif)
            ->take(2)->all();

        if (count($matches) > 1) {
            throw ValidationException::withMessages(['nif' => __('More than one client in Contiu has this tax ID. Review the records before syncing.')]);
        }

        return reset($matches) ?: null;
    }

    private function remote(string $subdomain, string $nif): ?array
    {
        $matches = array_filter($this->catalog->contacts($subdomain), fn (array $row): bool => self::remoteNif($row) === $nif);
        if (count($matches) > 1) {
            throw ValidationException::withMessages(['nif' => __('More than one customer in InvoiceXpress has this tax ID. Review the records before syncing.')]);
        }

        if ($matches === []) {
            return null;
        }

        $match = reset($matches);
        $id = InvoiceXpressIssueTerms::positiveId($match['id'] ?? null);
        if ($id === null) {
            throw new InvoiceXpressException(__('InvoiceXpress returned an invalid customer record.'));
        }

        return $this->readRemote($subdomain, $id, $nif);
    }

    private function readRemote(string $subdomain, int $id, string $nif): array
    {
        $remote = $this->api->request('GET', "/clients/{$id}.json", expectedSubdomain: $subdomain)['client'] ?? [];
        if (InvoiceXpressIssueTerms::positiveId($remote['id'] ?? null) !== $id || self::remoteNif($remote) !== $nif) {
            throw new InvoiceXpressException(__('The InvoiceXpress record changed during the lookup.'));
        }

        return [...$remote, 'id' => $id];
    }

    /** @return array{name: string, email?: string, phone?: string, address?: string} */
    private function contactFields(array $source, bool $forExport = false): array
    {
        $details = [];
        foreach (['name', 'email', 'phone', 'address'] as $field) {
            $value = $source[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $details[$field] = trim($value);
            }
        }

        if (mb_strlen($details['name'] ?? '') === 0 || mb_strlen($details['name']) > ($forExport ? 100 : 255)
            || mb_strlen($details['phone'] ?? '') > ($forExport ? 20 : 255)
            || mb_strlen($details['email'] ?? '') > 254
            || (isset($details['email']) && filter_var($details['email'], FILTER_VALIDATE_EMAIL) === false)) {
            throw ValidationException::withMessages(['nif' => __('Check the name, email and phone number on the source record.')]);
        }

        return $details;
    }

    private function remoteContactFields(array $source): array
    {
        return [...$this->contactFields($source), 'language' => $source['language'] ?? null];
    }

    private static function remoteNif(array $row): ?string
    {
        return self::nif($row['fiscal_id'] ?? null, $row['country'] ?? null);
    }

    private static function nif(mixed $value, mixed $country): ?string
    {
        if (! is_string($value) || ($country !== null && ! is_string($country))
            || ! in_array(strtoupper(trim((string) $country)), ['', 'PT', 'PORTUGAL'], true)) {
            return null;
        }

        return InvoiceXpressIssueTerms::nif(preg_replace('/[\s.\-]+/', '', $value));
    }

    private static function invalidNif(): ValidationException
    {
        return ValidationException::withMessages(['nif' => __('Enter a valid Portuguese tax ID. The generic final consumer number does not identify a client.')]);
    }
}
