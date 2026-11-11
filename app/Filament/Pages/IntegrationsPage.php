<?php

namespace App\Filament\Pages;

use App\Models\InvoiceXpressConfig;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Components\Attributes\Unexposed;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class IntegrationsPage extends Page
{
    protected static ?string $slug = 'integracoes';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::Plugs;

    protected string $view = 'filament.pages.integrations-page';

    #[Unexposed]
    public static function canAccess(): bool
    {
        return InvoiceXpressSettingsPage::canAccess();
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('Integrations');
    }

    #[Unexposed]
    public static function getNavigationGroup(): string
    {
        return __('Settings');
    }

    /** @return list<string> */
    #[Unexposed]
    public static function getNavigationItemActiveRoutePattern(): array
    {
        return [
            static::getRouteName(),
            InvoiceXpressSettingsPage::getRouteName(),
            InvoiceEmailInboxPage::getRouteName(),
            CalendarSubscriptionPage::getRouteName(),
        ];
    }

    protected function getViewData(): array
    {
        return [
            'invoiceXpressConfigured' => InvoiceXpressConfig::current() !== null,
            'invoiceXpressSettingsUrl' => InvoiceXpressSettingsPage::getUrl(),
            'invoiceEmailInboxUrl' => app()->environment(['local', 'testing']) && InvoiceEmailInboxPage::canAccess()
                ? InvoiceEmailInboxPage::getUrl()
                : null,
            'calendarSubscriptionConfigured' => CalendarSubscriptionPage::isConfigured(),
            'calendarSubscriptionUrl' => CalendarSubscriptionPage::getUrl(),
        ];
    }
}
