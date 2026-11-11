<?php

namespace App\Support;

use App\Enums\FiscalProfilePartyType;
use App\Enums\PortugueseVatRegion;
use App\Models\Client;

final class PortugueseVatSuggestion
{
    public static function forClient(Client $client): ?PortugueseVatRegion
    {
        if ((int) $client->account_id !== CurrentAccount::get()?->getKey()) {
            return null;
        }

        if ($client->fiscal_country_code !== null && $client->fiscal_country_code !== 'PT') {
            return null;
        }

        return match ($client->fiscal_party_type) {
            FiscalProfilePartyType::Business => $client->structuredAddress?->vatRegion() ?? $client->vat_region,
            FiscalProfilePartyType::Individual => app(FinanceSettings::class)->vatRegion(),
            null => null,
        };
    }
}
