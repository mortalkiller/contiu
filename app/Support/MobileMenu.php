<?php

namespace App\Support;

use BackedEnum;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The phone's "Mais" sheet, built from the panel's own navigation so there is no second menu to
 * keep up to date: a new resource or page shows up here by itself, in its group and order.
 *
 * How a group looks is decided where the groups are declared (AdminPanelProvider):
 *  - an open group shows its pages as tiles;
 *  - a NavigationGroup::make(…)->collapsed() group is one row that opens the list of its pages.
 * The same flag starts the group closed in the desktop sidebar.
 */
final class MobileMenu
{
    /**
     * @param  list<string>  $excludedUrls  Pages already on the tab bar, left out of the sheet.
     * @return list<array{
     *     label: string|null,
     *     collapsed: bool,
     *     items: list<array{label: string, url: string, icon: string|BackedEnum|Htmlable|null, badge: string|null, badgeColor: string, active: bool}>
     * }>
     */
    public static function sections(array $excludedUrls = []): array
    {
        $sections = [];

        foreach (Filament::getNavigation() as $group) {
            /** @var NavigationGroup $group */
            $items = [];

            foreach ($group->getItems() as $item) {
                /** @var NavigationItem $item */
                $url = $item->getUrl();

                if ($url === null || in_array($url, $excludedUrls, true)) {
                    continue;
                }

                $badgeColor = $item->getBadgeColor();

                $items[] = [
                    'label' => (string) $item->getLabel(),
                    'url' => $url,
                    'icon' => $item->getIcon(),
                    'badge' => $item->getBadge(),
                    'badgeColor' => is_string($badgeColor) ? $badgeColor : 'gray',
                    'active' => $item->isActive(),
                ];
            }

            if ($items === []) {
                continue;
            }

            $label = $group->getLabel();

            $sections[] = [
                'label' => $label === null ? null : (string) $label,
                // Pages outside any group have no row to hide behind: always tiles.
                'collapsed' => $label !== null && $group->isCollapsed(),
                'items' => $items,
            ];
        }

        return $sections;
    }
}
