<?php

namespace App\Support\Invoicing;

use App\Enums\IntegrationCategory;
use App\Models\IntegrationConnection;
use App\Support\AccountantAccess;
use App\Support\CurrentAccount;

/**
 * The account's billing provider, if it has one, and who may issue through it. Resolved once per
 * request and per account, so a screen full of documents asks the database only once.
 */
final class Invoicing
{
    /** @var array<int, ?IntegrationConnection> */
    private array $connections = [];

    /** @var array<int, ?InvoicingProvider> */
    private array $providers = [];

    /** Issuing is for the account's owner, with a verified email, on an account that is not read-only. */
    public static function canIssue(): bool
    {
        $account = CurrentAccount::get();

        return $account !== null && $account->hasAccess() && ! CurrentAccount::readOnly() && AccountantAccess::canManage($account);
    }

    public static function connection(): ?IntegrationConnection
    {
        $billing = app(self::class);
        $accountId = CurrentAccount::id();

        if (! array_key_exists($accountId, $billing->connections)) {
            $billing->connections[$accountId] = IntegrationConnection::query()->where('category', IntegrationCategory::Invoicing)->first();
        }

        return $billing->connections[$accountId];
    }

    public static function provider(): ?InvoicingProvider
    {
        $billing = app(self::class);
        $accountId = CurrentAccount::id();

        if (! array_key_exists($accountId, $billing->providers)) {
            $connection = self::connection();
            $billing->providers[$accountId] = $connection === null ? null : InvoicingProviders::for($connection);
        }

        return $billing->providers[$accountId];
    }

    public static function connected(): bool
    {
        return self::connection() !== null;
    }

    /** The connected provider's name, for "Issued by". */
    public static function providerLabel(): ?string
    {
        $connection = self::connection();

        return $connection === null ? null : InvoicingProviders::label($connection->provider);
    }

    /** After connecting, disconnecting or changing settings. */
    public static function forget(): void
    {
        $billing = app(self::class);
        $billing->connections = [];
        $billing->providers = [];
    }
}
