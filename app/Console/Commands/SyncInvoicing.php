<?php

namespace App\Console\Commands;

use App\Actions\SyncInvoicingDocuments;
use App\Enums\IntegrationCategory;
use App\Exceptions\InvoicingProviderException;
use App\Models\Account;
use App\Models\IntegrationConnection;
use App\Models\Scopes\AccountScope;
use App\Support\CurrentAccount;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('invoicing:sync')]
#[Description('Compare each account\'s documents with its invoicing provider')]
class SyncInvoicing extends Command
{
    public function handle(SyncInvoicingDocuments $sync): int
    {
        $failed = 0;

        IntegrationConnection::query()
            ->withoutGlobalScope(AccountScope::class)
            ->where('category', IntegrationCategory::Invoicing)
            ->with('account')
            ->each(function (IntegrationConnection $connection) use ($sync, &$failed): void {
                $account = $connection->account;

                // A read-only account gets nothing new until it subscribes; an owner may also turn the automation off.
                if (! $account instanceof Account || ! $account->hasAccess() || ! $connection->setting('auto_sync', true)) {
                    return;
                }

                try {
                    CurrentAccount::run($account, fn () => $sync->handle());
                } catch (InvoicingProviderException) {
                    // Recorded on the connection and in its log by the action; the next account still runs.
                    $failed++;
                }
            });

        $this->info(__('Accounts that could not be synchronised: :count', ['count' => $failed]));

        return self::SUCCESS;
    }
}
