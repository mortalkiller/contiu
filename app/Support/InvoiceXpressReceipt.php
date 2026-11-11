<?php

namespace App\Support;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Enums\Language;
use App\Enums\PaymentMethod;
use App\Exceptions\InvoiceXpressException;
use App\Models\Account;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLink;
use App\Models\InvoiceXpressReceiptAttempt;
use App\Models\InvoiceXpressSnapshot;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Receivable;
use App\Models\WithholdingRecord;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class InvoiceXpressReceipt
{
    private const FINAL_RECEIPT_STATES = ['final', 'sent', 'settled'];

    public function __construct(private readonly InvoiceXpressClient $client) {}

    /** @return array{allocationId: int, paymentId: int, charge: string, invoice: string, paidOn: string, amount: int, series: string, revision: string, withholding?: array, canIssue?: bool} */
    public function preview(int $allocationId): array
    {
        abort_unless(InvoiceXpressIssueRepository::ownerCanIssue(), 403);
        $allocation = PaymentAllocation::query()->findOrFail($allocationId);
        if ($this->attemptFor($allocation)->exists()) {
            throw $this->invalid(__('An attempt already exists for this allocation. Check the history.'));
        }

        $local = $this->local($allocation);
        $intent = $this->preflight($local);

        return [
            'allocationId' => $allocationId,
            'paymentId' => $local['paymentId'],
            'charge' => $local['charge'],
            'invoice' => $local['invoiceNumber'],
            'paidOn' => CarbonImmutable::parse($local['paidOnIso'])->format(Language::resolve(app()->getLocale())->dateFormat()),
            'amount' => $intent['withholding']['gross'] ?? $local['amount'],
            'canIssue' => InvoiceXpressIssue::canWriteDocuments() && ! isset($intent['withholding']),
            ...(isset($intent['withholding']) ? ['withholding' => $intent['withholding']] : []),
            'series' => $intent['series']['name'],
            'revision' => $this->revision($intent),
        ];
    }

    public function confirm(int $allocationId, string $revision): InvoiceXpressReceiptAttempt
    {
        try {
            $id = $this->locked(function () use ($allocationId, $revision): int {
                $allocation = PaymentAllocation::query()->findOrFail($allocationId);
                if (($existing = $this->attemptFor($allocation)->first()) !== null) {
                    return $existing->id;
                }

                $this->requireWriteAccess();
                $local = $this->local($allocation, lock: true);
                $intent = $this->preflight($local);
                $this->requirePaymentContract($intent);
                if (! hash_equals($this->revision($intent), $revision)) {
                    throw $this->invalid(__('The details have changed. Preview the receipt again before confirming.'));
                }

                $attempt = new InvoiceXpressReceiptAttempt;
                $attempt->forceFill([
                    'confirmed_by' => auth()->id(),
                    'payment_allocation_id' => $allocationId,
                    'payment_id' => $local['paymentId'],
                    'receivable_id' => $local['receivableId'],
                    'subdomain' => $local['subdomain'],
                    'provider_invoice_id' => $local['providerInvoiceId'],
                    'note' => 'Contiu recibo '.Str::uuid(),
                    'intent' => $intent,
                    'pending_target' => $this->revision([$local['subdomain'], $local['providerInvoiceId']]),
                ])->save();

                // A rollback of any enclosing transaction must also suppress the POST.
                $accountId = CurrentAccount::id();
                DB::afterCommit(function () use ($accountId, $attempt): void {
                    if (CurrentAccount::get()?->id === $accountId && auth()->id() === $attempt->confirmed_by
                        && InvoiceXpressIssueRepository::ownerCanIssue()) {
                        $this->send($attempt->id);
                    }
                });

                return $attempt->id;
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->invalid(__('An attempt already exists for this allocation or invoice. Check the history.'));
        }

        return InvoiceXpressReceiptAttempt::query()->findOrFail($id);
    }

    public function recover(int $attemptId): InvoiceXpressReceiptAttempt
    {
        return $this->locked(function () use ($attemptId): InvoiceXpressReceiptAttempt {
            $attempt = InvoiceXpressReceiptAttempt::query()->lockForUpdate()->findOrFail($attemptId);

            if ($attempt->provider_receipt_id === null) {
                if ($this->client->configuration()->subdomain !== $attempt->subdomain) {
                    throw $this->invalid(__('The InvoiceXpress account has changed. Reconnect to the account used for the attempt to verify the receipt.'));
                }

                $this->resolve($attempt);
            }

            return $attempt;
        });
    }

    private function send(int $attemptId): void
    {
        $this->locked(function () use ($attemptId): void {
            $attempt = InvoiceXpressReceiptAttempt::query()->lockForUpdate()->findOrFail($attemptId);
            if ($attempt->provider_receipt_id !== null) {
                return;
            }

            try {
                $allocation = PaymentAllocation::query()->find($attempt->payment_allocation_id);
                if ($allocation === null || $this->preflight($this->local($allocation, lock: true)) !== $attempt->intent) {
                    return;
                }

                $this->requireWriteAccess();
                $this->requirePaymentContract($attempt->intent);
                $intent = $attempt->intent;
                $local = $intent['local'];
                $result = $this->client->request(
                    'POST',
                    "/documents/{$attempt->provider_invoice_id}/partial_payments.json",
                    payload: ['partial_payment' => [
                        'amount' => Money::toEuros($local['amount']),
                        'payment_mechanism' => 'TB',
                        'payment_date' => $local['paidOn'],
                        'serie' => $intent['series']['name'],
                        'note' => $attempt->note,
                    ]],
                    expectedSubdomain: $attempt->subdomain,
                );

                $reportedId = $result['receipt']['id'] ?? null;
                if (is_int($reportedId) && $reportedId > 0) {
                    $attempt->forceFill(['reported_receipt_id' => $reportedId])->save();
                }
            } catch (InvoiceXpressException|ValidationException) {
                return;
            }

            $this->resolve($attempt);
        });
    }

    private function resolve(InvoiceXpressReceiptAttempt $attempt): void
    {
        $intent = $attempt->intent;
        $local = $intent['local'];

        try {
            $documents = $this->related($local);
            $matches = array_values(array_filter($documents, fn (array $document): bool => ($document['observations'] ?? null) === $attempt->note));
            if (count($matches) !== 1) {
                return;
            }

            $receipt = $this->verifiedReceipt($matches[0], $attempt);
            $allocation = PaymentAllocation::query()->find($attempt->payment_allocation_id);
            if ($receipt === null || $allocation === null || $this->local($allocation, lock: true) !== $local
                || FiscalDocument::query()->where('direction', FiscalDocumentDirection::Issued)->where('type', FiscalDocumentType::Receipt)
                    ->where('number', $receipt['number'])->exists()
                || InvoiceXpressSnapshot::query()->where('subdomain', $attempt->subdomain)->where('provider_id', $receipt['id'])->exists()) {
                return;
            }

            if (isset($intent['withholding'])) {
                $prior = array_values(array_filter($documents, fn (array $document): bool => $document['id'] !== $receipt['id']));
                if (count($prior) !== count($documents) - 1 || $this->preflight($local, $prior) !== $intent) {
                    return;
                }
            }
        } catch (InvoiceXpressException|ValidationException) {
            return;
        }

        $data = [
            'direction' => FiscalDocumentDirection::Issued->value,
            'type' => FiscalDocumentType::Receipt->value,
            'status' => FiscalDocumentStatus::Active->value,
            'number' => $receipt['number'],
            'origin' => 'InvoiceXpress',
            'document_date' => $local['paidOnIso'],
            'currency' => 'EUR',
            'net_amount' => $receipt['net'],
            'vat_amount' => $receipt['vat'],
            'total_amount' => $intent['withholding']['gross'] ?? $local['amount'],
            'party_name' => $local['partyName'],
            'party_tax_id' => $local['partyTaxId'],
        ];
        $document = FiscalDocument::query()->create($data);
        $document->links()->create([
            'payment_id' => $local['paymentId'],
            ...(isset($intent['withholding']) ? ['receivable_id' => $local['receivableId']] : []),
            'related_fiscal_document_id' => $local['invoiceId'],
        ]);
        $source = [
            'provider_type' => 'Receipt', 'document' => $data,
            ...(isset($intent['withholding']) ? ['withholding' => array_intersect_key($intent['withholding'], array_flip(['gross', 'cash', 'retained']))] : []),
        ];
        InvoiceXpressSnapshot::query()->create([
            'subdomain' => $attempt->subdomain,
            'provider_id' => $receipt['id'],
            'source_hash' => $this->revision($source),
            'source' => $source,
            'fiscal_document_id' => $document->id,
            'user_id' => auth()->id(),
        ]);
        $attempt->forceFill([
            'provider_receipt_id' => $receipt['id'],
            'receipt_number' => $receipt['number'],
            'receipt_atcud' => $receipt['atcud'],
            'fiscal_document_id' => $document->id,
            'pending_target' => null,
        ])->save();

        if (($intent['withholding']['retained'] ?? 0) > 0) {
            WithholdingReview::record($document->id, $intent['withholding']['retained'], allocationId: $local['allocationId']);
        }
    }

    private function local(PaymentAllocation $allocation, bool $lock = false): array
    {
        $receivable = Receivable::query()->excludingCancelled()->when($lock, fn (Builder $query) => $query->lockForUpdate())->find($allocation->receivable_id);
        $payment = Payment::query()->when($lock, fn (Builder $query) => $query->lockForUpdate())->find($allocation->payment_id);
        if ($lock && $payment !== null && $receivable !== null) {
            $allocation = $payment->allocations()->where('receivable_id', $receivable->id)->lockForUpdate()->find($allocation->id)
                ?? throw $this->invalid(__('The allocation has changed. Preview the receipt again.'));
        }

        if ($payment === null || $receivable === null || $payment->isAdvance() || $payment->method !== PaymentMethod::Transfer
            || $allocation->amount <= 0 || $allocation->amount > $payment->amount || $allocation->amount > $receivable->amount
            || $payment->paid_on->isAfter(today()) || ! $allocation->allocated_on->isSameDay($payment->paid_on)
            || (int) $payment->allocations()->sum('amount') !== $payment->amount) {
            throw $this->invalid(__('The allocation must belong to a recorded transfer with a valid date and amount.'));
        }

        $invoices = $this->issuedDocuments([FiscalDocumentType::Invoice, FiscalDocumentType::InvoiceReceipt])
            ->whereHas('links', fn (Builder $query) => $query->where('receivable_id', $receivable->id))
            ->when($lock, fn (Builder $query) => $query->lockForUpdate())->limit(2)->get();
        $invoice = $invoices->first();

        if ($invoices->count() !== 1 || $invoice->type !== FiscalDocumentType::Invoice || $invoice->currency !== 'EUR'
            || $invoice->document_date->isAfter($payment->paid_on)) {
            throw $this->invalid(__('The receivable must have one active invoice in EUR, issued on or before the payment date.'));
        }

        if ($invoice->links?->related_fiscal_document_id !== null
            || $receivable->payments()->whereNotNull('payments.advance_client_id')->exists()) {
            throw $this->invalid(__('For payments after a deposit, reconcile an already issued receipt locally.'));
        }

        if ($invoice->total_amount !== $receivable->amount) {
            throw $this->invalid(__('The invoice total does not match the receivable total. Reconcile an already issued receipt locally.'));
        }

        if (WithholdingRecord::query()->where(fn (Builder $query) => $query
            ->where('payment_allocation_id', $allocation->id)
            ->orWhere(fn (Builder $query) => $query->where('payment_id', $payment->id)->whereNull('payment_allocation_id'))
            ->orWhere('fiscal_document_id', $invoice->id))->exists()) {
            throw $this->invalid(__('A confirmed withholding record already exists for this allocation. Reconcile the existing receipt.'));
        }

        $subdomain = $this->client->configuration()->subdomain;
        $evidence = $this->withholdingEvidence($invoice->id, $allocation->id, $subdomain);

        if ($receivable->creditApplications()->effectiveAt(today()->toDateString())->exists()
            || $this->hasUntrackedLocalDocument($invoice->id, $receivable->id, $payment->id, array_column($evidence, 'documentId'))) {
            throw $this->invalid(__('This receivable already has receipts or local adjustments awaiting reconciliation.'));
        }

        $snapshots = InvoiceXpressSnapshot::query()->where('fiscal_document_id', $invoice->id)
            ->where('subdomain', $subdomain)->latest('id')->get();
        $snapshot = $snapshots->first();
        $providerType = $snapshot?->source['provider_type'] ?? null;

        if ($invoice->origin !== 'InvoiceXpress' || $snapshot === null || $snapshot->provider_id < 1
            || $snapshots->contains(fn (InvoiceXpressSnapshot $other): bool => $other->provider_id !== $snapshot->provider_id)
            || ! in_array($providerType, ['Invoice', 'SimplifiedInvoice'], true)
            || InvoiceXpressSnapshot::query()->where('subdomain', $subdomain)->where('provider_id', $snapshot->provider_id)
                ->latest('id')->first()?->id !== $snapshot->id) {
            throw $this->invalid(__('The original invoice is not unambiguously identified in the current InvoiceXpress account.'));
        }

        return [
            'accountId' => CurrentAccount::id(),
            'allocationId' => $allocation->id,
            'receivableId' => $receivable->id,
            'paymentId' => $payment->id,
            'paymentAmount' => $payment->amount,
            'paidOn' => $payment->paid_on->format('d/m/Y'),
            'paidOnIso' => $payment->paid_on->toDateString(),
            'charge' => $receivable->label,
            'amount' => $allocation->amount,
            'invoiceId' => $invoice->id,
            'invoiceNumber' => $invoice->number,
            'invoiceDate' => $invoice->document_date->format('d/m/Y'),
            'invoiceTotal' => $invoice->total_amount,
            'invoiceNet' => $invoice->net_amount,
            'invoiceVat' => $invoice->vat_amount,
            'partyName' => $invoice->party_name,
            'partyTaxId' => $invoice->party_tax_id,
            'subdomain' => $subdomain,
            'providerInvoiceId' => $snapshot->provider_id,
            'providerType' => $providerType,
            'snapshotId' => $snapshot->id,
            'sourceHash' => $snapshot->source_hash,
            ...(isset($snapshot->source['withholding']) ? ['invoiceWithholding' => $snapshot->source['withholding']] : []),
            ...($evidence === [] ? [] : ['withholdingEvidence' => $evidence]),
        ];
    }

    private function verifiedInvoice(array $local, bool $recovering = false): array
    {
        $key = $local['providerType'] === 'Invoice' ? 'invoice' : 'simplified_invoice';
        $response = $this->client->request('GET', "/{$key}s/{$local['providerInvoiceId']}.json", expectedSubdomain: $local['subdomain']);
        $invoice = $response[$key] ?? null;

        if (! is_array($invoice) || ($invoice['id'] ?? null) !== $local['providerInvoiceId']
            || ($invoice['type'] ?? null) !== $local['providerType']
            || ! in_array($invoice['status'] ?? null, $recovering ? self::FINAL_RECEIPT_STATES : ['final', 'sent'], true) || ($invoice['archived'] ?? null) !== false
            || ! $this->isEuro($invoice) || $this->number($invoice) !== $local['invoiceNumber']
            || ($invoice['date'] ?? null) !== $local['invoiceDate']
            || $this->cents($invoice['before_taxes'] ?? null) !== $local['invoiceNet']
            || $this->cents($invoice['taxes'] ?? null) !== $local['invoiceVat']
            || ! is_int($clientId = $invoice['client']['id'] ?? null) || $clientId < 1) {
            throw $this->invalid(__('The InvoiceXpress invoice does not match the local record or is closed to payments.'));
        }

        return [$invoice, $key, $clientId, $this->invoiceRetention($invoice, $local)];
    }

    private function preflight(array $local, ?array $relatedBeforeReceipt = null): array
    {
        [$invoice, $key, $clientId, $retained] = $this->verifiedInvoice($local, recovering: $relatedBeforeReceipt !== null);

        $series = $this->receiptSeries($invoice, $key, $local['subdomain']);
        $known = InvoiceXpressReceiptAttempt::query()->where('subdomain', $local['subdomain'])
            ->where('provider_invoice_id', $local['providerInvoiceId'])->whereNotNull('provider_receipt_id')
            ->get()->keyBy('provider_receipt_id');
        $verified = [];
        $paidCash = 0;
        $paidRetained = 0;

        foreach ($relatedBeforeReceipt ?? $this->related($local) as $document) {
            $id = $document['id'];
            if (($id === $local['providerInvoiceId'] && $document['type'] === $local['providerType']) || $document['status'] === 'canceled') {
                continue;
            }

            $prior = $known->get($id);
            $evidence = $local['withholdingEvidence'][$id] ?? null;
            $receipt = $prior === null ? null : $this->verifiedReceipt($document, $prior);
            if (isset($verified[$id]) || ($prior !== null && ($receipt === null || $receipt['number'] !== $prior->receipt_number
                || $receipt['atcud'] !== $prior->receipt_atcud || ! $this->hasRecordedReceipt($prior)))
                || ($prior === null && ($evidence === null || ! $this->matchesEvidence($document, $evidence, $clientId, $series)))) {
                throw $this->invalid(__('Reconcile the pending InvoiceXpress transactions before issuing another receipt.'));
            }

            $cash = $prior?->intent['local']['amount'] ?? $evidence['cash'];
            $retention = $prior?->intent['withholding']['retained'] ?? ($evidence['retained'] ?? 0);
            $paidCash += $cash;
            $paidRetained += $retention;
            $verified[$id] = $receipt['number'] ?? $evidence['number'];
        }

        if (array_diff($known->keys()->all(), array_keys($verified)) !== []
            || array_diff(array_keys($local['withholdingEvidence'] ?? []), array_keys($verified)) !== []
            || $local['amount'] > $local['invoiceTotal'] - $paidCash - $paidRetained) {
            throw $this->invalid(__('The InvoiceXpress invoice balance has not been confirmed for this amount.'));
        }

        ksort($verified);

        $withholding = null;
        if ($retained > 0) {
            $invoiceCash = $local['invoiceTotal'] - $retained;
            $cumulativeCash = $paidCash + $local['amount'];
            if ($cumulativeCash > $invoiceCash || $paidRetained !== $this->proportional($paidCash, $retained, $invoiceCash)) {
                throw $this->invalid(__('The confirmed cash and withholding do not match the cumulative invoice settlement.'));
            }

            $currentRetained = $this->proportional($cumulativeCash, $retained, $invoiceCash) - $paidRetained;
            $withholding = [
                'cash' => $local['amount'], 'retained' => $currentRetained, 'gross' => $local['amount'] + $currentRetained,
                'invoiceRetained' => $retained, 'invoiceCash' => $invoiceCash,
            ];
        } elseif ($paidRetained !== 0) {
            throw $this->invalid(__('The confirmed cash and withholding do not match the cumulative invoice settlement.'));
        }

        return ['local' => $local, 'clientId' => $clientId, 'series' => $series, 'relatedReceipts' => $verified,
            ...($withholding === null ? [] : ['withholding' => $withholding])];
    }

    /** @return array{id: int, name: string, validationCode: string} */
    private function receiptSeries(array $invoice, string $invoiceKind, string $subdomain): array
    {
        $invoiceSequenceId = InvoiceXpressIssueTerms::positiveId($invoice['sequence_id'] ?? null);
        if ($invoiceSequenceId === null) {
            throw $this->invalid(__('The original invoice series is not identified in InvoiceXpress.'));
        }

        $response = $this->client->request('GET', '/sequences.json', expectedSubdomain: $subdomain);
        $sequences = $response['sequences'] ?? null;
        if (! is_array($sequences) || ! array_is_list($sequences)) {
            throw $this->invalid(__('InvoiceXpress did not return a valid list of series.'));
        }

        $matches = [];
        $names = [];
        foreach ($sequences as $sequence) {
            if (! is_array($sequence) || ! is_string($name = $sequence['serie'] ?? null)) {
                throw $this->invalid(__('InvoiceXpress returned an invalid series.'));
            }

            $key = strtolower($name);
            $names[$key] = ($names[$key] ?? 0) + 1;
            if (InvoiceXpressIssueTerms::positiveId($sequence["current_{$invoiceKind}_sequence_id"] ?? null) === $invoiceSequenceId) {
                $matches[] = $sequence;
            }
        }

        $series = $matches[0] ?? [];
        $name = $series['serie'] ?? '';
        $id = InvoiceXpressIssueTerms::positiveId($series['current_receipt_sequence_id'] ?? null);
        $code = $series['current_receipt_validation_code'] ?? null;
        if (count($matches) !== 1 || ($names[strtolower($name)] ?? 0) !== 1
            || InvoiceXpressIssueTerms::positiveId($series['id'] ?? null) === null || $id === null
            || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,34}\z/', $name)
            || ! is_string($code) || ! preg_match('/\A[A-Z0-9]{8,32}\z/', $code)
            || $this->sequenceNumber($invoice, $name) === null) {
            throw $this->invalid(__('The original invoice series requires a receipt series registered with the Portuguese Tax Authority and identified unambiguously.'));
        }

        return ['id' => $id, 'name' => $name, 'validationCode' => $code];
    }

    /** @return list<array> */
    private function related(array $local): array
    {
        $response = $this->client->request(
            'GET',
            "/document/{$local['providerInvoiceId']}/related_documents.json",
            expectedSubdomain: $local['subdomain'],
        );
        $documents = $response['documents'] ?? null;

        if (! is_array($documents) || ! array_is_list($documents)) {
            throw $this->invalid(__('InvoiceXpress did not return a valid list of related documents.'));
        }

        foreach ($documents as $document) {
            if (! is_array($document) || ! is_int($document['id'] ?? null) || $document['id'] < 1
                || ! is_string($document['type'] ?? null) || ! is_string($document['status'] ?? null)) {
                throw $this->invalid(__('InvoiceXpress returned an invalid related document.'));
            }
        }

        return $documents;
    }

    /** @return array{id: int, number: string, net: int, vat: int, atcud: string}|null */
    private function verifiedReceipt(array $document, InvoiceXpressReceiptAttempt $attempt): ?array
    {
        $intent = $attempt->intent;
        $local = $intent['local'];
        $series = $intent['series'];
        $id = $document['id'];
        $number = $this->number($document);
        $serial = $this->sequenceNumber($document, $series['name']);
        $atcud = $document['atcud'] ?? null;
        $net = $this->cents($document['before_taxes'] ?? null);
        $vat = $this->cents($document['taxes'] ?? null);
        $retained = $intent['withholding']['retained'] ?? 0;
        $gross = $intent['withholding']['gross'] ?? $local['amount'];

        if ($id === $attempt->provider_invoice_id || ($attempt->reported_receipt_id !== null && $id !== $attempt->reported_receipt_id)
            || ($document['type'] ?? null) !== 'Receipt' || ! in_array($document['status'] ?? null, self::FINAL_RECEIPT_STATES, true)
            || ($document['observations'] ?? null) !== $attempt->note || ($document['date'] ?? null) !== $local['paidOn']
            || ! $this->isEuro($document) || $this->reportedRetention($document) !== $retained
            || ($document['client']['id'] ?? null) !== $intent['clientId'] || $number === null
            || InvoiceXpressIssueTerms::positiveId($document['sequence_id'] ?? null) !== $series['id']
            || $serial === null || $atcud !== $series['validationCode'].'-'.$serial
            || $net === null || $vat === null || $net + $vat !== $gross
            || $gross !== $local['amount'] + $retained
            || $this->cents($document['total'] ?? null) !== $local['amount']) {
            return null;
        }

        return ['id' => $id, 'number' => $number, 'net' => $net, 'vat' => $vat, 'atcud' => $atcud];
    }

    private function hasRecordedReceipt(InvoiceXpressReceiptAttempt $attempt): bool
    {
        return $this->issuedDocuments([FiscalDocumentType::Receipt])->whereKey($attempt->fiscal_document_id)
            ->where('number', $attempt->receipt_number)->where('total_amount', $attempt->intent['withholding']['gross'] ?? $attempt->intent['local']['amount'])
            ->when(($attempt->intent['withholding']['retained'] ?? 0) > 0, fn (Builder $query) => $query
                ->whereIn('id', WithholdingReview::confirmedAllocations()->select('withholding_records.fiscal_document_id')
                    ->where('withholding_records.retained_amount', $attempt->intent['withholding']['retained'])))
            ->whereHas('links', fn (Builder $query) => $query->where('payment_id', $attempt->payment_id)
                ->where('related_fiscal_document_id', $attempt->intent['local']['invoiceId']))
            ->exists();
    }

    private function hasUntrackedLocalDocument(int $invoiceId, int $receivableId, int $paymentId, array $confirmedDocumentIds = []): bool
    {
        return FiscalDocumentLink::query()
            ->whereNotIn('fiscal_document_id', $confirmedDocumentIds)
            ->where(function (Builder $query) use ($invoiceId, $receivableId, $paymentId): void {
                $query->where('related_fiscal_document_id', $invoiceId)->orWhere('receivable_id', $receivableId)
                    ->orWhere(fn (Builder $query) => $query->where('payment_id', $paymentId)->whereNull('related_fiscal_document_id'));
            })
            ->whereIn('fiscal_document_id', $this->issuedDocuments([
                FiscalDocumentType::Receipt, FiscalDocumentType::InvoiceReceipt, FiscalDocumentType::CreditNote, FiscalDocumentType::DebitNote,
            ])->select('id'))
            ->whereNotIn('fiscal_document_id', InvoiceXpressReceiptAttempt::query()->whereNotNull('provider_receipt_id')
                ->whereNotNull('fiscal_document_id')->select('fiscal_document_id'))
            ->exists();
    }

    /** @param list<FiscalDocumentType> $types @return Builder<FiscalDocument> */
    private function issuedDocuments(array $types): Builder
    {
        return FiscalDocument::query()->where('direction', FiscalDocumentDirection::Issued)
            ->where('status', FiscalDocumentStatus::Active)->whereIn('type', $types);
    }

    /** @return Builder<InvoiceXpressReceiptAttempt> */
    private function attemptFor(PaymentAllocation $allocation): Builder
    {
        return InvoiceXpressReceiptAttempt::query()->where(fn (Builder $query) => $query
            ->where('payment_allocation_id', $allocation->id)
            ->orWhere(fn (Builder $query) => $query->where('payment_id', $allocation->payment_id)->where('receivable_id', $allocation->receivable_id)));
    }

    private function locked(Closure $work): mixed
    {
        abort_unless(InvoiceXpressIssueRepository::ownerCanIssue(), 403);

        return DB::transaction(function () use ($work): mixed {
            $account = Account::query()->lockForUpdate()->findOrFail(CurrentAccount::id());
            abort_unless($account->hasAccess() && AccountantAccess::canManage($account), 403);

            return $work();
        });
    }

    private function requireWriteAccess(): void
    {
        if (! InvoiceXpressIssue::canWriteDocuments()) {
            throw $this->invalid(__('Document issuance is disabled for this InvoiceXpress account.'));
        }
    }

    private function requirePaymentContract(array $intent): void
    {
        if (isset($intent['withholding'])) {
            // The payment endpoint does not specify whether amount means cash or gross on a retained invoice.
            throw $this->invalid(__('Issuing receipts with withholding requires validation in the InvoiceXpress sandbox.'));
        }
    }

    private function invoiceRetention(array $invoice, array $local): int
    {
        $cash = $this->cents($invoice['total'] ?? null);
        if ($cash === null) {
            throw $this->invalid(__('The InvoiceXpress invoice does not match the local record or is closed to payments.'));
        }

        $retained = $local['invoiceTotal'] - $cash;
        $reported = $this->reportedRetention($invoice);
        $saved = $local['invoiceWithholding'] ?? null;
        $rate = $saved['basisPoints'] ?? null;
        $matchesRate = is_int($rate) && $rate > 0 && $rate <= 10000 && $reported === $rate
            && $retained === InvoiceXpressIssueTerms::withholdingAmounts($rate, $local['invoiceNet'], $local['invoiceTotal'])['retained'];

        if ($reported === null || $retained < 0 || $retained > $local['invoiceNet'] || $cash <= 0
            || $local['invoiceTotal'] !== $local['invoiceNet'] + $local['invoiceVat']
            || ($saved !== null && (($saved['gross'] ?? null) !== $local['invoiceTotal']
                || ($saved['retained'] ?? null) !== $retained || ($saved['cash'] ?? null) !== $cash))
            || ($reported !== $retained && ! $matchesRate)) {
            throw $this->invalid(__('The withholding on the original invoice could not be verified.'));
        }

        return $retained;
    }

    private function reportedRetention(array $document): ?int
    {
        return array_key_exists('retention', $document)
            ? ($document['retention'] === null ? 0 : $this->cents($document['retention']))
            : null;
    }

    private function proportional(int $part, int $total, int $whole): int
    {
        $half = intdiv($whole, 2);
        if ($total > intdiv(PHP_INT_MAX - $half, max(1, $part))) {
            throw $this->invalid(__('The confirmed cash and withholding do not match the cumulative invoice settlement.'));
        }

        return intdiv($part * $total + $half, $whole);
    }

    /** @return array<int, array> Indexed by the provider receipt ID. */
    private function withholdingEvidence(int $invoiceId, int $allocationId, string $subdomain): array
    {
        $records = WithholdingRecord::query()->where('original_document_id', $invoiceId)->with('document')->get();
        if ($records->isEmpty()) {
            return [];
        }

        $confirmed = WithholdingReview::confirmedAllocations()
            ->where('withholding_records.original_document_id', $invoiceId)
            ->where('withholding_records.settles_receivable', true)
            ->where('withholding_records.payment_allocation_id', '!=', $allocationId)
            ->where('withholding_records.retained_amount', '>', 0)
            ->pluck('withholding_records.id')->flip();
        $evidence = [];
        foreach ($records as $record) {
            $document = $record->document;
            $snapshots = InvoiceXpressSnapshot::query()->where('subdomain', $subdomain)
                ->where('fiscal_document_id', $record->fiscal_document_id)->latest('id')->get();
            $snapshot = $snapshots->first();
            if (! $confirmed->has($record->id) || $document?->type !== FiscalDocumentType::Receipt
                || $document->origin !== 'InvoiceXpress' || $snapshot === null || $snapshot->provider_id < 1
                || ($snapshot->source['provider_type'] ?? null) !== 'Receipt'
                || isset($evidence[$snapshot->provider_id])
                || $snapshots->contains(fn (InvoiceXpressSnapshot $other): bool => $other->provider_id !== $snapshot->provider_id)
                || InvoiceXpressSnapshot::query()->where('subdomain', $subdomain)->where('provider_id', $snapshot->provider_id)
                    ->latest('id')->first()?->id !== $snapshot->id) {
                throw $this->invalid(__('Reconcile the pending InvoiceXpress transactions before issuing another receipt.'));
            }

            $evidence[$snapshot->provider_id] = [
                'documentId' => $document->id, 'recordId' => $record->id, 'sourceHash' => $snapshot->source_hash,
                'number' => $document->number, 'date' => $document->document_date->format('d/m/Y'),
                'cash' => $record->gross_amount - $record->retained_amount, 'retained' => $record->retained_amount,
                'net' => $document->net_amount, 'vat' => $document->vat_amount,
            ];
        }

        ksort($evidence);

        return $evidence;
    }

    private function matchesEvidence(array $document, array $evidence, int $clientId, array $series): bool
    {
        $serial = $this->sequenceNumber($document, $series['name']);

        return ($document['type'] ?? null) === 'Receipt' && in_array($document['status'] ?? null, self::FINAL_RECEIPT_STATES, true)
            && ($document['client']['id'] ?? null) === $clientId && $this->isEuro($document)
            && ($document['date'] ?? null) === $evidence['date'] && $this->number($document) === $evidence['number']
            && InvoiceXpressIssueTerms::positiveId($document['sequence_id'] ?? null) === $series['id']
            && $serial !== null && ($document['atcud'] ?? null) === $series['validationCode'].'-'.$serial
            && $this->reportedRetention($document) === $evidence['retained']
            && $this->cents($document['total'] ?? null) === $evidence['cash']
            && $this->cents($document['before_taxes'] ?? null) === $evidence['net']
            && $this->cents($document['taxes'] ?? null) === $evidence['vat'];
    }

    private function isEuro(array $document): bool
    {
        return in_array($document['currency'] ?? null, ['EUR', 'Euro'], true);
    }

    private function number(array $document): ?string
    {
        $number = $document['inverted_sequence_number'] ?? $document['sequence_number'] ?? null;

        return is_string($number) && trim($number) !== '' && mb_strlen($number) <= 255 && ! preg_match('/[[:cntrl:]]/', $number)
            ? $number : null;
    }

    private function sequenceNumber(array $document, string $name): ?string
    {
        $number = $this->number($document);
        $series = preg_quote($name, '#');
        $pattern = isset($document['inverted_sequence_number'])
            ? "#\A{$series}/([1-9][0-9]{0,18})\z#"
            : "#\A([1-9][0-9]{0,18})/{$series}\z#";

        return $number !== null && preg_match($pattern, $number, $parts) ? $parts[1] : null;
    }

    private function cents(mixed $value): ?int
    {
        if (is_float($value)) {
            $value = json_encode($value);
        }

        if ((! is_string($value) && ! is_int($value))
            || ! preg_match('/\A([0-9]{1,10})(?:\.([0-9]{1,2}))?\z/', (string) $value, $parts)) {
            return null;
        }

        return ((int) $parts[1] * 100) + (int) str_pad($parts[2] ?? '', 2, '0');
    }

    private function revision(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function invalid(string $message): ValidationException
    {
        return ValidationException::withMessages(['receipt' => $message]);
    }
}
