<?php

namespace App\Support;

use App\Models\Client;
use App\Models\DirectInvoiceSale;
use App\Models\InvoiceXpressIssueAttempt;
use App\Models\Receivable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class InvoiceXpressDirectSale
{
    public static function findReceivable(InvoiceXpressIssueAttempt $attempt): ?Receivable
    {
        if (! $attempt->exists || (int) $attempt->account_id !== CurrentAccount::id()) {
            return null;
        }

        return DirectInvoiceSale::query()->where('issue_attempt_id', $attempt->getKey())->first()?->receivable;
    }

    /** The caller holds the account and attempt locks in the finalization transaction. */
    public static function record(InvoiceXpressIssueAttempt $attempt): Receivable
    {
        if (! $attempt->exists || (int) $attempt->account_id !== CurrentAccount::id()) {
            throw ValidationException::withMessages(['direct' => __('The issuance must belong to the current account.')]);
        }

        if (($receivable = self::findReceivable($attempt)) !== null) {
            return $receivable;
        }

        $snapshot = Validator::make($attempt->snapshot ?? [], [
            'direct.key' => ['required', 'uuid'],
            'direct.clientId' => ['required', 'integer:strict', 'min:1'],
            'direct.dueOn' => ['required', 'date_format:Y-m-d'],
            'totals.total' => ['required', 'integer:strict', 'min:1'],
            'issueOn' => ['required', 'date_format:Y-m-d'],
            'tax.basisPoints' => ['sometimes', 'integer:strict', 'between:0,10000'],
        ])->validate();

        $client = Client::query()->find($snapshot['direct']['clientId']);

        if ($client === null) {
            throw ValidationException::withMessages(['direct.clientId' => __('The client for the direct invoice must belong to the current account.')]);
        }

        $vatBasisPoints = $snapshot['tax']['basisPoints'] ?? null;
        $sale = DirectInvoiceSale::query()->create([
            'issue_attempt_id' => $attempt->getKey(),
            'key' => $snapshot['direct']['key'],
            'client_id' => $client->getKey(),
            'issued_on' => $snapshot['issueOn'],
            'vat_rate' => $vatBasisPoints === null ? null : $vatBasisPoints / 10000,
        ]);

        $receivable = $sale->receivables()->create([
            'description' => 'Fatura direta',
            'amount' => $snapshot['totals']['total'],
            'due_on' => $snapshot['direct']['dueOn'],
        ]);

        $sale->receivable()->associate($receivable)->save();

        return $receivable;
    }
}
