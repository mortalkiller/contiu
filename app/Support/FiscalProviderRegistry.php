<?php

namespace App\Support;

use App\Models\InvoiceXpressConfig;

final class FiscalProviderRegistry
{
    public function current(): ?FiscalProviderCapabilities
    {
        if (CurrentAccount::get() === null) {
            return null;
        }

        $config = InvoiceXpressConfig::current();

        return $config === null
            ? null
            : new InvoiceXpressFiscalProvider($config->account_id, $config->subdomain);
    }
}
