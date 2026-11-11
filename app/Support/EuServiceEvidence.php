<?php

namespace App\Support;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Enums\FiscalProfilePartyType;
use App\Enums\FiscalProfileViesStatus;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Models\FiscalProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** @phpstan-type Terms array{country: string, vat: string, operationOn: string, reason: 'M40', clientId: int, fiscalProfileId: int, viesCheckedAt: string} */
final class EuServiceEvidence
{
    public const REASON = 'M40';

    private const VAT_FORMATS = [
        'AT' => 'U[0-9]{8}',
        'BE' => '[01][0-9]{9}',
        'BG' => '[0-9]{9,10}',
        'CY' => '[0-9]{8}[A-Z]',
        'CZ' => '[0-9]{8,10}',
        'DE' => '[0-9]{9}',
        'DK' => '[0-9]{8}',
        'EE' => '[0-9]{9}',
        'ES' => '[A-Z0-9][0-9]{7}[A-Z0-9]',
        'FI' => '[0-9]{8}',
        'FR' => '[A-Z0-9]{2}[0-9]{9}',
        'GR' => '[0-9]{9}',
        'HR' => '[0-9]{11}',
        'HU' => '[0-9]{8}',
        'IE' => '(?:[0-9]{7}[A-Z]{1,2}|[0-9][A-Z*+][0-9]{5}[A-Z])',
        'IT' => '[0-9]{11}',
        'LT' => '(?:[0-9]{9}|[0-9]{12})',
        'LU' => '[0-9]{8}',
        'LV' => '[0-9]{11}',
        'MT' => '[0-9]{8}',
        'NL' => '[0-9]{9}B[0-9]{2}',
        'PL' => '[0-9]{10}',
        'RO' => '[0-9]{2,10}',
        'SE' => '[0-9]{12}',
        'SI' => '[0-9]{8}',
        'SK' => '[0-9]{10}',
    ];

    /** @return Terms */
    public static function terms(Client $client, string $operationOn): array
    {
        $day = self::operationDate($operationOn);
        $client = self::currentClient($client);
        $identity = $client->viesIdentity();
        $country = self::country($identity['country'] ?? null);
        $vat = self::vat($identity['vat'] ?? null, $country);
        $checked = $client->vat_validated_at;

        if ($vat === null || ($identity['party'] ?? null) !== FiscalProfilePartyType::Business->value
            || $client->vat_validation_status !== FiscalProfileViesStatus::Valid || $checked === null || $checked->isFuture()) {
            throw self::invalid(__('Confirm the organization, tax country and valid VIES result for the current VAT number.'));
        }

        $profile = FiscalProfile::effectiveOn($day);
        if ($profile === null || $profile->confirmed_at === null || $profile->confirmed_at->isFuture()
            || $profile->country_code !== 'PT' || $profile->vat_exempt === true
            || ! in_array(self::regime($profile), ['normal', 'normal mensal', 'normal trimestral'], true)) {
            throw self::invalid(__('Confirm that a Portuguese tax profile under the standard VAT regime was in effect on the transaction date.'));
        }

        return [
            'country' => $country,
            'vat' => $vat,
            'operationOn' => $operationOn,
            'reason' => self::REASON,
            'clientId' => $client->getKey(),
            'fiscalProfileId' => $profile->getKey(),
            'viesCheckedAt' => $checked->toDateTimeString(),
        ];
    }

    /** @param array<string, mixed> $remote */
    public static function catalogVat(array $remote): ?string
    {
        return self::vat($remote['fiscal_id'] ?? null, self::country($remote['country'] ?? null));
    }

    /**
     * Called after the final provider document matches the approved issuance snapshot.
     *
     * @param  Terms  $terms
     */
    public static function record(FiscalDocument $document, array $terms): void
    {
        self::store($document, $terms, 'issuance');
    }

    /**
     * The operator confirms M40 and the operation date against the issued document.
     *
     * @param  array{checkedOn: string, reference: string}|null  $historicalVies  A retained valid VIES result for the same VAT number.
     */
    public static function recordDocumentary(FiscalDocument $document, Client $client, string $operationOn, ?array $historicalVies = null): void
    {
        self::store($document, self::terms($client, $operationOn), 'documentary', $historicalVies);
    }

    public static function isDocumentCandidate(FiscalDocument $document): bool
    {
        return $document->direction === FiscalDocumentDirection::Issued
            && in_array($document->type, [FiscalDocumentType::Invoice, FiscalDocumentType::InvoiceReceipt], true)
            && $document->currency === 'EUR'
            && $document->vat_amount === 0
            && $document->net_amount > 0
            && $document->net_amount === $document->total_amount
            && filled($document->party_tax_id);
    }

    /** @return list<string> */
    public static function vatPrefixes(): array
    {
        return array_map(self::prefix(...), array_keys(self::VAT_FORMATS));
    }

    /** @return list<string> */
    public static function countryCodes(): array
    {
        return [...array_keys(self::VAT_FORMATS), 'EL'];
    }

    public static function recapMonths(FiscalProfile $profile): ?int
    {
        if ($profile->country_code !== 'PT' || $profile->vat_exempt === true || $profile->confirmed_at === null || $profile->confirmed_at->isFuture()) {
            return null;
        }

        return match (self::regime($profile)) {
            'normal mensal' => 1,
            'normal trimestral' => 3,
            default => null,
        };
    }

    /**
     * @param  Terms  $terms
     * @param  array{checkedOn: string, reference: string}|null  $historicalVies
     */
    private static function store(FiscalDocument $document, array $terms, string $source, ?array $historicalVies = null): void
    {
        if (! $document->exists || $document->account_id !== CurrentAccount::id()) {
            throw self::invalid(__('Select a fiscal document from the current account.'));
        }

        DB::transaction(function () use ($document, $terms, $source, $historicalVies): void {
            $saved = FiscalDocument::query()->lockForUpdate()->findOrFail($document->getKey());
            $existing = $saved->fiscalOperationEvidence()->first();

            if ($existing !== null) {
                if (! self::sameTerms($existing->terms(), $terms)
                    || $existing->historical_vies_on?->toDateString() !== ($historicalVies['checkedOn'] ?? null)
                    || $existing->historical_vies_reference !== ($historicalVies['reference'] ?? null)) {
                    throw self::invalid(__('This document already has tax evidence on record.'));
                }

                if (! $existing->matchesDocument($saved)) {
                    throw self::invalid(__('The document changed after the tax evidence was recorded and needs review.'));
                }

                return;
            }

            if ($source === 'issuance') {
                self::validateIssuedTerms($saved, $terms);
            } else {
                $client = Client::query()->lockForUpdate()->find($terms['clientId']);
                if ($client === null || ! self::sameTerms(self::terms($client, $terms['operationOn']), $terms)) {
                    throw self::invalid(__('The tax details for the transaction have changed. Confirm the classification again.'));
                }
            }

            $operationOn = $terms['operationOn'];

            if ($saved->status !== FiscalDocumentStatus::Active || ! self::isDocumentCandidate($saved)
                || self::vat($saved->party_tax_id, $terms['country']) !== $terms['vat']
                || $saved->document_date->toDateString() < $operationOn
                || ($saved->operation_date !== null && $saved->operation_date->toDateString() !== $operationOn)) {
                throw self::invalid(__('The transaction details do not match the invoice issued in euros with no VAT charged.'));
            }

            self::validateHistoricalVies($terms, $source, $historicalVies);

            $saved->fiscalOperationEvidence()->create([
                'client_id' => $terms['clientId'],
                'fiscal_profile_id' => $terms['fiscalProfileId'],
                'country_code' => $terms['country'],
                'vat_number' => $terms['vat'],
                'operation_on' => $operationOn,
                'document_on' => $saved->document_date,
                'document_operation_on' => $saved->operation_date,
                'document_type' => $saved->type->value,
                'document_number' => $saved->number,
                'reason' => $terms['reason'],
                'vies_checked_at' => $terms['viesCheckedAt'],
                'historical_vies_on' => $historicalVies['checkedOn'] ?? null,
                'historical_vies_reference' => $historicalVies['reference'] ?? null,
                'net_amount' => $saved->net_amount,
                'source' => $source,
                'recorded_by' => auth()->id(),
            ]);
        });
    }

    /** @param Terms $terms */
    private static function validateIssuedTerms(FiscalDocument $document, array $terms): void
    {
        $country = $terms['country'] ?? null;
        $vat = $terms['vat'] ?? null;
        $checked = $terms['viesCheckedAt'] ?? null;
        $clientId = $terms['clientId'] ?? null;
        $profileId = $terms['fiscalProfileId'] ?? null;

        if (! is_string($country) || ! isset(self::VAT_FORMATS[$country]) || $vat === null || self::vat($vat, $country) !== $vat
            || ($terms['reason'] ?? null) !== self::REASON || ! is_string($terms['operationOn'] ?? null)
            || ! is_string($checked) || ! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} (?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]\z/', $checked)
            || substr($checked, 0, 10) > $document->document_date->toDateString()
            || ! is_int($clientId) || $clientId < 1 || ! is_int($profileId) || $profileId < 1
            || ! FiscalProfile::query()->whereKey($profileId)->exists()) {
            throw self::invalid(__('The evidence confirmed at issuance does not match the fiscal document.'));
        }

        self::operationDate($terms['operationOn']);
        self::operationDate(substr($checked, 0, 10));
    }

    /**
     * @param  Terms  $terms
     * @param  array{checkedOn: string, reference: string}|null  $historicalVies
     */
    private static function validateHistoricalVies(array $terms, string $source, ?array $historicalVies): void
    {
        if ($historicalVies === null) {
            if ($source === 'documentary' && substr($terms['viesCheckedAt'], 0, 10) > $terms['operationOn']) {
                throw self::invalid(__('The recorded VIES check is dated after the transaction. Provide the historical evidence.'), 'historicalViesOn');
            }

            return;
        }

        $checkedOn = self::operationDate($historicalVies['checkedOn'] ?? '', 'historicalViesOn');
        if ($checkedOn->toDateString() > $terms['operationOn']) {
            throw self::invalid(__('The VIES evidence must be dated on or before the transaction date.'), 'historicalViesOn');
        }

        $reference = $historicalVies['reference'] ?? '';
        if (trim($reference) === '' || ! mb_check_encoding($reference) || mb_strlen($reference) > 255 || str_contains($reference, "\0")) {
            throw self::invalid(__('Enter the VIES evidence reference.'), 'historicalViesReference');
        }
    }

    private static function sameTerms(array $expected, array $given): bool
    {
        foreach ($expected as $key => $value) {
            if (($given[$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    private static function currentClient(Client $client): Client
    {
        $saved = $client->exists && (int) $client->account_id === CurrentAccount::id()
            ? Client::query()->find($client->getKey())
            : null;

        return $saved ?? throw self::invalid(__('Select a client from the current account.'));
    }

    private static function operationDate(string $value, string $field = 'operationOn'): CarbonImmutable
    {
        if (! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value)
            || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))
            || $value > today()->toDateString()) {
            throw self::invalid(__('Enter a valid date.'), $field);
        }

        return CarbonImmutable::parse($value);
    }

    private static function regime(FiscalProfile $profile): string
    {
        return Str::of($profile->vat_regime)->lower()->squish()->replaceMatches('/\s*[–-]\s*/u', ' ')
            ->replaceStart('regime ', '')->toString();
    }

    private static function country(mixed $value): ?string
    {
        if (! is_string($value) || ! mb_check_encoding($value)) {
            return null;
        }

        $value = Str::lower(Str::ascii(trim($value)));
        if (strlen($value) === 2) {
            $code = $value === 'el' ? 'GR' : strtoupper($value);

            return isset(self::VAT_FORMATS[$code]) ? $code : null;
        }

        foreach (array_keys(self::VAT_FORMATS) as $code) {
            if (in_array($value, [
                Str::lower(Str::ascii(\Locale::getDisplayRegion('-'.$code, 'en'))),
                Str::lower(Str::ascii(\Locale::getDisplayRegion('-'.$code, 'pt_PT'))),
            ], true)) {
                return $code;
            }
        }

        return match ($value) {
            'czech republic', 'republica checa' => 'CZ',
            'the netherlands' => 'NL',
            default => null,
        };
    }

    private static function prefix(string $country): string
    {
        return $country === 'GR' ? 'EL' : $country;
    }

    private static function vat(mixed $value, ?string $country): ?string
    {
        if ($country === null || ! is_string($value)) {
            return null;
        }

        $value = preg_replace('/[\s.\-]+/', '', strtoupper($value));
        $prefix = self::prefix($country);
        $format = self::VAT_FORMATS[$country];

        if (! preg_match("/\\A(?:{$prefix})?({$format})\\z/", $value, $parts)) {
            return null;
        }

        return $prefix.$parts[1];
    }

    private static function invalid(string $message, string $field = 'operationOn'): ValidationException
    {
        return ValidationException::withMessages([$field => $message]);
    }
}
