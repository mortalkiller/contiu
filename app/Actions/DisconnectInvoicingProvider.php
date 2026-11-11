<?php

namespace App\Actions;

use App\Enums\FiscalDocumentStatus;
use App\Enums\IntegrationCategory;
use App\Models\Account;
use App\Models\FiscalDocument;
use App\Models\IntegrationConnection;
use App\Support\CurrentAccount;
use App\Support\Invoicing\Invoicing;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Disconnects the invoicing provider. The documents stay in the app; what goes is the link to
 * the provider, so drafts still waiting to be issued there have to be issued or deleted first.
 */
final class DisconnectInvoicingProvider
{
    public function handle(): void
    {
        abort_unless(Invoicing::canIssue(), 403);

        DB::transaction(function (): void {
            Account::query()->lockForUpdate()->findOrFail(CurrentAccount::id());
            abort_unless(Invoicing::canIssue(), 403);

            $drafts = FiscalDocument::query()->where('status', FiscalDocumentStatus::Draft)->count();

            if ($drafts > 0) {
                throw ValidationException::withMessages(['provider' => trans_choice(
                    '{1} Issue or delete the draft waiting at the provider first.|[2,*] Issue or delete the :count drafts waiting at the provider first.',
                    $drafts,
                )]);
            }

            IntegrationConnection::query()->where('category', IntegrationCategory::Invoicing)->delete();
        });

        Invoicing::forget();
    }
}
