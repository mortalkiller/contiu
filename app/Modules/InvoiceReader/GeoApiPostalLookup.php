<?php

namespace App\Modules\InvoiceReader;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GeoApiPostalLookup
{
    /** @return array{locality: ?string, streets: list<string>}|null */
    public function lookup(string $postalCode): ?array
    {
        if (! preg_match('/\A[1-9][0-9]{3}-[0-9]{3}\z/', $postalCode)) {
            return null;
        }

        return Cache::remember('invoice-benchmark:geoapi:'.$postalCode, now()->addDay(), function () use ($postalCode): ?array {
            try {
                $response = Http::acceptJson()->connectTimeout(1)->timeout(3)->withoutRedirecting()
                    ->get('https://geoapi.pt/cp/'.$postalCode, ['json' => 1]);
            } catch (ConnectionException) {
                return null;
            }

            if (! $response->successful() || $response->json('CP') !== $postalCode) {
                return null;
            }

            $locality = $this->addressLabel($response->json('Localidade'))
                ?? $this->addressLabel($response->json('Designação Postal'));
            $streets = [];
            foreach ((array) $response->json('partes', []) as $part) {
                $street = $this->addressLabel(data_get($part, 'Artéria'));
                if ($street !== null) {
                    $streets[mb_strtolower($street)] = $street;
                }
            }
            if ($streets === []) {
                foreach ((array) $response->json('ruas', []) as $value) {
                    $street = $this->addressLabel($value);
                    if ($street !== null) {
                        $streets[mb_strtolower($street)] = $street;
                    }
                }
            }

            return ['locality' => $locality, 'streets' => array_values($streets)];
        });
    }

    private function addressLabel(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = Str::squish($value);

        return $value !== '' && mb_strlen($value) <= 200 && preg_match('/\pL/u', $value) ? $value : null;
    }
}
