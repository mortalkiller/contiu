<?php

namespace App\Filament\Pages;

use Filament\Navigation\NavigationItem;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Pages\Page;
use Filament\Support\Components\Attributes\Unexposed;

abstract class InvoiceXpressSectionPage extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    private const array Tabs = [
        InvoiceXpressIssuePage::class => 'Invoices',
        InvoiceXpressReceiptPage::class => 'Receipts',
        InvoiceXpressNoteIssuePage::class => 'Credit and debit notes',
        InvoiceXpressImport::class => 'Import',
        InvoiceXpressSettingsPage::class => 'Configuration',
    ];

    public static function getNavigationItemActiveRoutePattern(): array
    {
        return array_map(fn (string $page): string => $page::getRouteName(), array_keys(self::Tabs));
    }

    /** @return list<NavigationItem> */
    #[Unexposed]
    public function getSubNavigation(): array
    {
        $items = [];

        foreach (self::Tabs as $page => $label) {
            if ($page::canAccess()) {
                $items[] = NavigationItem::make(__($label))
                    ->sort(count($items))
                    ->url($page::getUrl())
                    ->activeWhen(fn (): bool => static::class === $page);
            }
        }

        return $items;
    }
}
