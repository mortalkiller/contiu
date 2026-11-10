<?php

namespace App\Filament\Support;

use App\Support\AccountantAccess;
use Filament\GlobalSearch\GlobalSearchResults;
use Filament\GlobalSearch\Providers\DefaultGlobalSearchProvider;

final class AccountantAwareGlobalSearch extends DefaultGlobalSearchProvider
{
    public function getResults(string $query): ?GlobalSearchResults
    {
        return AccountantAccess::isReviewOnly() ? null : parent::getResults($query);
    }
}
