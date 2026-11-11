<?php

namespace App\Support;

use App\Exceptions\AccountIsReadOnly;
use App\Models\Account;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentDraft;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InvoiceXpressDraftSync
{
    private const array Types = [
        'invoice' => 'Invoice',
        'invoice_receipt' => 'InvoiceReceipt',
        ...InvoiceXpressNoteTerms::Types,
    ];

    public function __construct(
        private readonly InvoiceXpressClient $client,
        private readonly InvoiceXpressImport $import,
        private readonly InvoiceXpressIssue $issue,
    ) {}

    public function refresh(int $draftId): FiscalDocumentDraft
    {
        if (CurrentAccount::readOnly() || AccountantAccess::isReviewOnly()) {
            throw new AccountIsReadOnly;
        }

        return DB::transaction(function () use ($draftId): FiscalDocumentDraft {
            Account::query()->lockForUpdate()->findOrFail(CurrentAccount::id());
            $draft = FiscalDocumentDraft::query()->lockForUpdate()->findOrFail($draftId);
            $this->sync($draft);

            return $draft;
        });
    }

    /** Called by the manual importer with the account and draft already locked. */
    public function refreshReviewed(FiscalDocumentDraft $draft, string $sourceHash, ?int $receivableId): FiscalDocument
    {
        return $this->sync($draft, $sourceHash, $receivableId)
            ?? throw $this->invalid(__('The InvoiceXpress document is still a draft.'));
    }

    private function sync(FiscalDocumentDraft $draft, ?string $sourceHash = null, ?int $receivableId = null): ?FiscalDocument
    {
        $type = $draft->document_type;
        $id = $draft->remote_id;
        if ($draft->provider !== 'invoicexpress' || ! isset(self::Types[$type]) || $id === null || $id < 1
            || ! in_array($draft->state, [FiscalDocumentDraft::Remote, FiscalDocumentDraft::Blocked, FiscalDocumentDraft::Promoted], true)) {
            throw $this->invalid(__('Choose a supported draft linked to InvoiceXpress.'));
        }

        $account = $draft->provider_account;
        $invoice = $this->client->request('GET', "/{$type}s/{$id}.json", expectedSubdomain: $account)[$type] ?? null;
        if (! is_array($invoice) || ($invoice['id'] ?? null) !== $id || ($invoice['type'] ?? null) !== self::Types[$type]) {
            throw $this->invalid(__('The InvoiceXpress document does not match the saved draft.'));
        }

        $isDraft = ($invoice['status'] ?? null) === 'draft' && $draft->fiscal_document_id === null;

        $preview = $draft->preview;
        $totals = $preview['totals'] ?? [];
        if ((! $isDraft && ! $this->hasOfficialNumber($invoice, $preview['sequence'] ?? []))
            || ! is_int($totals['net'] ?? null) || ! is_int($totals['tax'] ?? null) || ! is_int($totals['total'] ?? null)
            || $totals['total'] !== $totals['net'] + $totals['tax']
            || ! in_array($invoice['currency'] ?? null, ['EUR', 'Euro'], true)
            || ! InvoiceXpressIssueTerms::matchesAmounts($invoice, $totals, $preview['withholding'] ?? null)) {
            throw $this->invalid(__('The InvoiceXpress document does not match the saved draft.'));
        }

        $original = null;
        $chargeId = null;
        if (isset(InvoiceXpressNoteTerms::Types[$type])) {
            $original = $this->verifiedOriginal($draft, $invoice);
        } else {
            if (! isset($preview['invoice']['items'], $preview['customer']['id'], $preview['sequence']['id'], $preview['tax'])
                || ! $this->issue->matchesSandboxDraft($preview, $invoice, 'Contiu draft '.$draft->send_key)) {
                throw $this->invalid(__('The InvoiceXpress document does not match the saved draft.'));
            }

            $chargeId = InvoiceXpressIssueTerms::positiveId($preview['receivableId'] ?? null);
        }

        if ($isDraft) {
            if ($sourceHash === null && $draft->state === FiscalDocumentDraft::Blocked) {
                $draft->update(['state' => FiscalDocumentDraft::Remote]);
            }

            return null;
        }

        if ($receivableId !== null && ($original !== null || ($chargeId !== null && $receivableId !== $chargeId))) {
            throw $this->invalid(__('The fiscal document or its link needs review in Contiu.'));
        }

        return $this->import->confirmDraft($draft, $invoice, $sourceHash, $chargeId ?? $receivableId, $original?->id);
    }

    private function hasOfficialNumber(array $invoice, array $sequence): bool
    {
        $series = $sequence['name'] ?? null;
        $number = $invoice['inverted_sequence_number'] ?? null;
        $atcud = $invoice['atcud'] ?? null;

        if (! in_array($invoice['status'] ?? null, [...InvoiceXpressNoteTerms::Issued, 'canceled'], true)
            || ! isset($sequence['id']) || InvoiceXpressIssueTerms::positiveId($invoice['sequence_id'] ?? null) !== $sequence['id']
            || ! is_string($series) || ! is_string($number) || ! is_string($atcud)
            || ! preg_match('#\A'.preg_quote($series, '#').'/([1-9][0-9]{0,18})\z#', $number, $parts)) {
            return false;
        }

        if (isset($sequence['validationCode'])) {
            return $atcud === $sequence['validationCode'].'-'.$parts[1];
        }

        return $atcud === 'N/D' || preg_match('/\A[A-Z0-9]{8,32}-'.$parts[1].'\z/', $atcud) === 1;
    }

    private function verifiedOriginal(FiscalDocumentDraft $draft, array $note): FiscalDocument
    {
        $preview = $draft->preview;
        $saved = $preview['original'] ?? [];
        $original = FiscalDocument::query()->lockForUpdate()->find($saved['id'] ?? null);
        $source = $original?->invoiceXpressSnapshot;
        if ($original === null || $source?->subdomain !== $draft->provider_account
            || $source->provider_id !== ($saved['providerId'] ?? null)
            || $original->number !== ($saved['number'] ?? null)
            || $original->direction->value !== 'issued' || $original->status->value !== 'active'
            || ! in_array($original->type->value, ['invoice', 'invoice_receipt'], true)
            || ($preview['input']['kind'] ?? null) !== $draft->document_type
            || ! isset($preview['input']['reason'], $preview['input']['quantity'], $preview['payload']['date'],
                $preview['sequence']['id'], $saved['customer']['id'], $saved['customer']['nif'])
            || ! is_int($preview['input']['line'] ?? null)
            || ! isset($saved['lines'][$preview['input']['line'] - 1])) {
            throw $this->invalid(__('The original InvoiceXpress document could not be verified.'));
        }

        $providerId = $source->provider_id;
        $documents = $this->client->request('GET', "/document/{$providerId}/related_documents.json", expectedSubdomain: $draft->provider_account)['documents'] ?? null;
        $related = is_array($documents) && array_is_list($documents)
            ? array_values(array_filter($documents, fn (mixed $row): bool => is_array($row)
                && InvoiceXpressIssueTerms::positiveId($row['id'] ?? null) === $draft->remote_id))
            : [];

        if (count($related) !== 1 || ($related[0]['type'] ?? null) !== $note['type']
            || ($related[0]['status'] ?? null) !== $note['status']
            || (isset($related[0]['owner_invoice_id']) && InvoiceXpressIssueTerms::positiveId($related[0]['owner_invoice_id']) !== $providerId)) {
            throw $this->invalid(__('The original InvoiceXpress document could not be verified.'));
        }

        if (! InvoiceXpressNoteTerms::matchesDocument($note, $preview, $draft->remote_id, 'Contiu draft '.$draft->send_key, related: true)) {
            throw $this->invalid(__('The InvoiceXpress document does not match the saved draft.'));
        }

        return $original;
    }

    private function invalid(string $message): ValidationException
    {
        return ValidationException::withMessages(['draft' => $message]);
    }
}
