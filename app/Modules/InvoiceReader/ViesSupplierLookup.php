<?php

namespace App\Modules\InvoiceReader;

use App\Support\FiscalTerms;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ViesSupplierLookup
{
    private const string ENDPOINT = 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number';

    /**
     * @param  'supplier'|'customer'  $party
     * @return array<string, string>
     */
    public static function addressParts(string $address, string $party = 'supplier'): array
    {
        $address = Str::squish($address);
        preg_match_all('/(?<![\pL\pN-])([1-9][0-9]{3})\s*[\p{Pd}]\s*([0-9]{3})(?![\pL\pN-])/u', $address, $postcodes, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if (count($postcodes) > 1) {
            return [];
        }

        $parts = [];
        $streetLine = $address;
        if ($postcodes !== []) {
            [[$postalCode, $offset], [$prefix], [$suffix]] = $postcodes[0];
            $parts[$party.'_postal_code'] = $prefix.'-'.$suffix;
            $locality = trim(substr($address, $offset + strlen($postalCode)), ' ,;');
            $locality = preg_replace('/(?:[,;]\s*|\s+)Portugal\z/iu', '', $locality);
            if (preg_match('/\A(?!Portugal\z)[\pL\pM][\pL\pM .\x{2019}\x{0027}\p{Pd}]*\z/iu', $locality)) {
                $parts[$party.'_locality'] = $locality;
            }
            $streetLine = trim(substr($address, 0, $offset), ' ,;');
        }

        $streetPrefix = '(?:Rua|R\.|Avenida|Av\.?|Travessa|Tv\.?|Largo|Praça|Praceta|Alameda|Estrada|Caminho|Beco|Calçada|Rotunda|Pç\.?)\s+';
        if (! preg_match('/\A'.$streetPrefix.'/iu', $streetLine)) {
            return $parts;
        }

        if (preg_match('/\A(?<street>.+?)[,\s]+n(?:\s*(?:\.?\s*[º°o]\.?|\.)\s*|\s+)(?<door>[0-9]+[A-Z]?)(?=\z|[\s,;])/iu', $streetLine, $match)
            || ($postcodes !== [] && preg_match('/\A(?<street>'.$streetPrefix.'[\pL\pM .\x{2019}\x{0027}\p{Pd}]+)[,\s]+(?<door>[0-9]+[A-Z]?)\z/iu', $streetLine, $match))) {
            $parts[$party.'_street'] = trim($match['street'], ' ,;');
            $parts[$party.'_door_number'] = $match['door'];
        } elseif ($postcodes !== [] && preg_match('/\A'.$streetPrefix.'[\pL\pM .\x{2019}\x{0027}\p{Pd}]+\z/iu', $streetLine)) {
            $parts[$party.'_street'] = $streetLine;
        }

        return $parts;
    }

    /**
     * @param  'supplier'|'customer'  $party
     * @return array<string, string>
     */
    public function lookup(array $invoice, string $party): array
    {
        $country = strtoupper(trim($invoice[$party.'_country'] ?? ''));
        $nif = FiscalTerms::nif(InvoiceData::normalizeNif($invoice[$party.'_nif'] ?? null));

        if ($nif === null || ($country !== '' && $country !== 'PT')) {
            return [];
        }

        try {
            $response = Http::acceptJson()->connectTimeout(1)->timeout(3)->withoutRedirecting()
                ->post(self::ENDPOINT, ['countryCode' => 'PT', 'vatNumber' => $nif]);
        } catch (ConnectionException) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $data = $response->json();
        if (($data['valid'] ?? null) !== true || ($data['countryCode'] ?? null) !== 'PT'
            || ($data['vatNumber'] ?? null) !== $nif) {
            return [];
        }

        $details = [];
        foreach (['name', 'address'] as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value)) {
                $value = Str::squish($value);
                if ($value !== '' && $value !== '---' && mb_strlen($value) <= 500) {
                    $details[$party.'_'.$key] = $value;
                }
            }
        }

        return $details;
    }
}
