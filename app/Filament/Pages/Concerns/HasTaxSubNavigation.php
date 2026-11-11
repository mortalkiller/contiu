<?php

namespace App\Filament\Pages\Concerns;

use App\Filament\Pages\AnnualFiscalReview;
use App\Filament\Pages\IrsScenarioPage;
use App\Filament\Pages\SocialSecurity;
use App\Filament\Pages\TaxEstimate;
use App\Filament\Pages\VatReviewPage;
use App\Filament\Pages\WithholdingReviewPage;
use App\Support\FinanceSettings;
use App\Support\TaxObligation;
use App\Support\TaxObligations;
use Carbon\CarbonImmutable;
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

    /** As in the mock, every tax page is headed "Impostos": the tab says which one is open. */
    public function getHeading(): string
    {
        return __('Taxes');
    }

    /** @return list<NavigationItem> */
    public function getSubNavigation(): array
    {
        $items = [];
        $lateSocialSecurity = $this->lateSocialSecurityCount();

        foreach (static::taxPages() as $page => $label) {
            if (! $page::canAccess()) {
                continue;
            }

            $items[] = NavigationItem::make($label)
                ->url($page::getUrl())
                ->badge($page === SocialSecurity::class && $lateSocialSecurity > 0 ? (string) $lateSocialSecurity : null, color: 'danger')
                ->isActiveWhen(fn (): bool => $this instanceof $page);
        }

        return $items;
    }

    /**
     * The Social Security declarations and contributions past their deadline and not yet marked
     * done, for the count on its tab.
     */
    private function lateSocialSecurityCount(): int
    {
        $obligations = (new TaxObligations(app(FinanceSettings::class), CarbonImmutable::today()))->pending();

        return count(array_filter($obligations, fn (TaxObligation $obligation): bool => $obligation->status === 'overdue'
            && in_array($obligation->kind, ['ss', 'ss-declaration'], true)));
    }

    public static function getSubNavigationPosition(): SubNavigationPosition
    {
        return SubNavigationPosition::Top;
    }
}
