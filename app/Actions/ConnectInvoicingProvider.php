<?php

namespace App\Actions;

use App\Enums\IntegrationCategory;
use App\Enums\IntegrationOperationStatus;
use App\Models\Account;
use App\Models\IntegrationConnection;
use App\Support\CurrentAccount;
use App\Support\Invoicing\Invoicing;
use App\Support\Invoicing\InvoicingProviders;
use App\Support\Invoicing\InvoicingRecords;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Connects the account to an invoicing provider, after proving the credentials work. An account
 * has one invoicing provider at a time: connecting another means disconnecting the first.
 */
final class ConnectInvoicingProvider
{
    /** What a new connection does until the owner says otherwise. */
    public const array DEFAULT_SETTINGS = ['issue_from_app' => true, 'auto_sync' => true, 'series' => null];

    /** @param array<string, mixed> $input */
    public function handle(string $provider, #[SensitiveParameter] array $input): IntegrationConnection
    {
        abort_unless(Invoicing::canIssue(), 403);

        $credentials = InvoicingProviders::driver($provider)::credentials($input);
        $candidate = new IntegrationConnection(['category' => IntegrationCategory::Invoicing, 'provider' => $provider, 'credentials' => $credentials]);
        InvoicingProviders::for($candidate)->test();

        $connection = DB::transaction(function () use ($provider, $credentials): IntegrationConnection {
            Account::query()->lockForUpdate()->findOrFail(CurrentAccount::id());
            abort_unless(Invoicing::canIssue(), 403);

            $current = IntegrationConnection::query()->where('category', IntegrationCategory::Invoicing)->lockForUpdate()->first();

            if ($current !== null && $current->provider !== $provider) {
                throw ValidationException::withMessages(['provider' => __('Disconnect :provider before connecting another invoicing provider.', [
                    'provider' => InvoicingProviders::label($current->provider),
                ])]);
            }

            $connection = $current ?? new IntegrationConnection(['category' => IntegrationCategory::Invoicing, 'provider' => $provider, 'settings' => self::DEFAULT_SETTINGS]);
            $connection->fill(['credentials' => $credentials, 'connected_by' => auth()->id(), 'last_error' => null])->save();

            return $connection;
        });

        Invoicing::forget();
        InvoicingRecords::log('connected', IntegrationOperationStatus::Done, __('Connected to :provider', ['provider' => InvoicingProviders::label($provider)]));

        return $connection;
    }
}
