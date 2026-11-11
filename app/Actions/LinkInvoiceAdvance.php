<?php

namespace App\Actions;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Models\Account;
use App\Models\FiscalDocument;
use App\Models\Payment;
use App\Models\Receivable;
use App\Support\AccountantAccess;
use App\Support\CurrentAccount;
use App\Support\InvoiceXpressIssueAdvance;
use App\Support\InvoiceXpressIssueTerms;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class LinkInvoiceAdvance
{
    public function handle(int $finalId, int $advanceId): void
    {
        $accountId = CurrentAccount::id();

        DB::transaction(function () use ($accountId, $finalId, $advanceId): void {
            $account = Account::query()->lockForUpdate()->findOrFail($accountId);
            abort_unless($account->hasAccess() && AccountantAccess::canManage($account), 403);

            $documents = FiscalDocument::query()->whereKey([$finalId, $advanceId])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $final = $documents->get($finalId) ?? abort(404);
            Gate::authorize('update', $final);
            $advance = $documents->get($advanceId);
            $link = $final->links;
            $nif = InvoiceXpressIssueTerms::nif($final->party_tax_id);

            if ($advance === null || $final->is($advance) || $final->type !== FiscalDocumentType::Invoice
                || ! in_array($advance->type, [FiscalDocumentType::Invoice, FiscalDocumentType::InvoiceReceipt], true)
                || ! $this->activeEuroDocument($advance) || ! $this->activeEuroDocument($final)
                || $advance->document_date->gt($final->document_date)
                || $nif === null || $nif !== InvoiceXpressIssueTerms::nif($advance->party_tax_id)
                || $link?->receivable_id === null
                || ($link->related_fiscal_document_id !== null && $link->related_fiscal_document_id !== $advanceId)) {
                throw ValidationException::withMessages(['advance' => __('Choose the unlinked advance invoice for this client.')]);
            }

            $payment = Payment::query()->lockForUpdate()->find($advance->links?->payment_id);
            $charge = Receivable::query()->excludingCancelled()->lockForUpdate()->find($link->receivable_id);

            if ($payment === null || ! $payment->isAdvance() || $charge === null
                || ! InvoiceXpressIssueAdvance::sourceForFinal($charge, $payment, $final)->is($advance)) {
                throw ValidationException::withMessages(['advance' => __('Confirm the deposit allocated to the receivable and the linked advance invoice.')]);
            }

            $link->update(['related_fiscal_document_id' => $advanceId]);
        }, attempts: 3);
    }

    private function activeEuroDocument(FiscalDocument $document): bool
    {
        return $document->direction === FiscalDocumentDirection::Issued
            && $document->status === FiscalDocumentStatus::Active
            && $document->currency === 'EUR'
            && $document->document_date->lte(today())
            && $document->net_amount + $document->vat_amount === $document->total_amount;
    }
}
