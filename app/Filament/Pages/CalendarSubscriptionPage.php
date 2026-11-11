<?php

namespace App\Filament\Pages;

use App\Support\AccountantAccess;
use App\Support\CalendarSubscriptions;
use App\Support\CurrentAccount;
use Filament\Pages\Page;
use Filament\Support\Components\Attributes\Unexposed;
use Livewire\Attributes\Locked;

class CalendarSubscriptionPage extends Page
{
    protected static ?string $slug = 'integracoes/subscricao-calendario';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.calendar-subscription';

    #[Locked]
    public int $accountId;

    #[Unexposed]
    public static function canAccess(): bool
    {
        $account = CurrentAccount::get();

        return $account !== null && AccountantAccess::canManage($account);
    }

    #[Unexposed]
    public static function isConfigured(): bool
    {
        return static::canAccess() && app(CalendarSubscriptions::class)->isConfigured();
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return __('Calendar subscription');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->accountId = CurrentAccount::id();
    }

    public function createSubscription(CalendarSubscriptions $subscriptions): void
    {
        $this->authorizeManagement();
        abort_if(CurrentAccount::readOnly(), 403);

        $subscriptions->create();
    }

    public function setIncludeReceivables(bool $include, CalendarSubscriptions $subscriptions): void
    {
        $this->authorizeManagement();
        abort_if(CurrentAccount::readOnly(), 403);

        $subscriptions->setIncludeReceivables($include);
    }

    public function revokeSubscription(CalendarSubscriptions $subscriptions): void
    {
        $this->authorizeManagement();

        $subscriptions->revoke();
    }

    /** @return array{subscription: array{url: string, includeReceivables: bool}|null, canWrite: bool} */
    protected function getViewData(): array
    {
        $this->authorizeManagement();

        return [
            'subscription' => app(CalendarSubscriptions::class)->settings(),
            'canWrite' => ! CurrentAccount::readOnly(),
        ];
    }

    private function authorizeManagement(): void
    {
        abort_unless(static::canAccess() && $this->accountId === CurrentAccount::id(), 403);
    }
}
