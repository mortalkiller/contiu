<?php

namespace App\Filament\Pages\Concerns;

use App\Filament\Pages\AnnualFiscalReview;
use App\Filament\Pages\IrsScenarioPage;
use App\Filament\Pages\SocialSecurity;
use App\Filament\Pages\TaxEstimate;
use App\Filament\Pages\VatReviewPage;
use App\Filament\Pages\WithholdingReviewPage;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Enums\SubNavigationPosition;

/**
 * The six tax pages read as one "Impostos" area: each shows the same row of tabs under its
 * heading, so moving between them does not mean going back to the menu. A page the user may not
 * open (canAccess) has no tab. The sidebar keeps its own entries.
 */
trait HasTaxSubNavigation
{
    /** @return array<class-string, string> the tabs, in order */
    public static function taxPages(): array
    {
        return [
            TaxEstimate::class => __('Estimate'),
            SocialSecurity::class => __('Social Security'),
            VatReviewPage::class => __('VAT review'),
            WithholdingReviewPage::class => __('Withholding taxes'),
            IrsScenarioPage::class => __('Personal income tax scenario'),
            AnnualFiscalReview::class => __('Annual summary'),
        ];
    }

    /** @return list<NavigationItem> */
    public function getSubNavigation(): array
    {
        $items = [];

        foreach (static::taxPages() as $page => $label) {
            if (! $page::canAccess()) {
                continue;
            }

            $items[] = NavigationItem::make($label)
                ->url($page::getUrl())
                ->isActiveWhen(fn (): bool => $this instanceof $page);
        }

        return $items;
    }

    public static function getSubNavigationPosition(): SubNavigationPosition
    {
        return SubNavigationPosition::Top;
    }
}
