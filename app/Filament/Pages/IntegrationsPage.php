<?php

namespace App\Filament\Pages;

use App\Enums\IntegrationRecordState;
use App\Models\FiscalDocument;
use App\Models\IntegrationRecord;
use App\Support\AccountantAccess;
use App\Support\CurrentAccount;
use App\Support\Invoicing\Invoicing;
use App\Support\Invoicing\InvoicingProviders;
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
        $account = CurrentAccount::get();

        return $account !== null && AccountantAccess::canManage($account);
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
            InvoicingProviderPage::getRouteName(),
            InvoiceEmailInboxPage::getRouteName(),
            CalendarSubscriptionPage::getRouteName(),
        ];
    }

    protected function getViewData(): array
    {
        return [
            'invoicingProviders' => $this->invoicingProviders(),
            'invoiceEmailInboxUrl' => app()->environment(['local', 'testing']) && InvoiceEmailInboxPage::canAccess()
                ? InvoiceEmailInboxPage::getUrl()
                : null,
            'calendarSubscriptionConfigured' => CalendarSubscriptionPage::isConfigured(),
            'calendarSubscriptionUrl' => CalendarSubscriptionPage::getUrl(),
        ];
    }

    /**
     * A card per invoicing provider the app knows, with the one the account is connected to
     * saying when it last synchronised and how many documents differ from the provider's.
     *
     * @return array<string, array{label: string, url: string, connected: bool, syncedAt: ?string, differences: ?string, syncUrl: string}>
     */
    private function invoicingProviders(): array
    {
        $connection = Invoicing::connection();
        $differences = $connection === null ? 0 : IntegrationRecord::query()
            ->where('integration_connection_id', $connection->getKey())
            ->where('record_type', (new FiscalDocument)->getMorphClass())
            ->where('state', IntegrationRecordState::Differs)
            ->count();

        $providers = [];

        foreach (array_keys(InvoicingProviders::all()) as $key) {
            $connected = $connection?->provider === $key;

            $providers[$key] = [
                'label' => InvoicingProviders::label($key),
                'url' => InvoicingProviderPage::getUrl(['provider' => $key]),
                'connected' => $connected,
                'syncedAt' => $connected ? $connection->last_synced_at?->diffForHumans() : null,
                'differences' => $connected && $differences > 0
                    ? trans_choice('{1} 1 difference|[2,*] :count differences', $differences)
                    : null,
                'syncUrl' => InvoicingSyncPage::getUrl(),
            ];
        }

        return $providers;
    }
}
