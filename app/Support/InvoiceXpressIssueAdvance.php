<?php

namespace App\Support;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLink;
use App\Models\Payment;
use App\Models\Receivable;
use Illuminate\Validation\ValidationException;

final class InvoiceXpressIssueAdvance
{
    public const string ProviderBlock = 'Settlement issuance is blocked until the credit note, full invoice and application of the credit are verified in the InvoiceXpress sandbox.';

    public static function source(Receivable $receivable, Payment $advance, int $vatRate, string $issueOn): FiscalDocument
    {
        return self::resolveSource($receivable, $advance, $vatRate, $issueOn);
    }

    /**
     * Prepare the credit-note and full-invoice workflow for review. The balance is an
     * account balance after credit, not the amount or VAT base of another invoice.
     * The withholding estimate is for that balance; it is not a provider payload.
     *
     * @param  array{net: int, tax: int, total: int, discount?: int}  $service
     * @return array{
     *     reference: string, date: string, paymentId: int,
     *     creditNote: array{originalDocumentId: int, totals: array{net: int, tax: int, total: int}},
     *     finalInvoice: array{totals: array{net: int, tax: int, total: int, discount?: int}},
     *     deduction: array{net: int, tax: int, total: int},
     *     received: array{cash: int, retained: int},
     *     balance: array{net: int, tax: int, total: int},
     *     remainingWithholding: array{basisPoints: int, gross: int, retained: int, cash: int}|null
     * }
     */
    public static function plan(Receivable $receivable, Payment $payment, array $service, int $vatRate, string $issueOn, int $withholdingBasisPoints = 0): array
    {
        if (! is_int($service['net'] ?? null) || ! is_int($service['tax'] ?? null) || ! is_int($service['total'] ?? null)
            || $service['net'] <= 0 || $service['tax'] < 0 || $service['total'] !== $service['net'] + $service['tax']
            || $service['total'] !== $receivable->amount || ! in_array($vatRate, [0, 600, 1300, 2300], true)
            || ($vatRate === 0 && $service['tax'] !== 0)
            || (array_key_exists('discount', $service) && (! is_int($service['discount']) || $service['discount'] < 0))) {
            throw ValidationException::withMessages(['advanceSettlement' => __('Check that the full invoice matches the receivable and covers the net amount and VAT of the advance.')]);
        }

        $advance = self::source($receivable, $payment, $vatRate, $issueOn);
        $link = $advance->links;
        if (Payment::query()->whereNotNull('advance_client_id')->whereKeyNot($payment->id)
            ->whereHas('allocations', fn ($allocations) => $allocations->where('receivable_id', $receivable->id))->exists()
            || $link?->expense_id !== null || $link?->related_fiscal_document_id !== null
            || ($link?->receivable_id !== null && $link->receivable_id !== $receivable->id)
            || FiscalDocumentLink::query()->where('receivable_id', $receivable->id)->where('fiscal_document_id', '!=', $advance->id)
                ->whereHas('fiscalDocument', fn ($documents) => $documents->where('status', FiscalDocumentStatus::Active)
                    ->where('direction', FiscalDocumentDirection::Issued)
                    ->whereIn('type', [FiscalDocumentType::Invoice, FiscalDocumentType::InvoiceReceipt, FiscalDocumentType::DebitNote]))
                ->exists()) {
            throw ValidationException::withMessages(['advanceSettlement' => __('Check that the receivable has a single advance and no existing final invoice or correction.')]);
        }

        if ($service['net'] < $advance->net_amount || $service['tax'] < $advance->vat_amount) {
            throw ValidationException::withMessages(['advanceSettlement' => __('Check that the full invoice matches the receivable and covers the net amount and VAT of the advance.')]);
        }

        $credit = ['net' => $advance->net_amount, 'tax' => $advance->vat_amount, 'total' => $advance->total_amount];
        $balance = [
            'net' => $service['net'] - $credit['net'],
            'tax' => $service['tax'] - $credit['tax'],
            'total' => $service['total'] - $credit['total'],
        ];

        return [
            'reference' => $advance->number,
            'date' => $advance->document_date->format('d/m/Y'),
            'paymentId' => $payment->id,
            'creditNote' => ['originalDocumentId' => $advance->id, 'totals' => $credit],
            'finalInvoice' => ['totals' => array_intersect_key($service, array_flip(['net', 'tax', 'total', 'discount']))],
            'deduction' => $credit,
            'received' => ['cash' => $payment->amount, 'retained' => $credit['total'] - $payment->amount],
            'balance' => $balance,
            'remainingWithholding' => $balance['net'] === 0
                ? null
                : InvoiceXpressIssueTerms::withholding($withholdingBasisPoints, $balance['net'], $balance['total']),
        ];
    }

    public static function sourceForFinal(Receivable $receivable, Payment $payment, FiscalDocument $final): FiscalDocument
    {
        $vatRate = match ($receivable->billable?->vat_rate) {
            '0.0000' => 0,
            '0.0600' => 600,
            '0.1300' => 1300,
            '0.2300' => 2300,
            default => null,
        };

        if ($vatRate === null || $final->net_amount < 0
            || $final->vat_amount !== self::vat($final->net_amount, $vatRate)
            || Payment::query()->whereNotNull('advance_client_id')->whereKeyNot($payment->id)
                ->whereHas('allocations', fn ($allocations) => $allocations->where('receivable_id', $receivable->id))->exists()) {
            throw ValidationException::withMessages(['advance' => __('Confirm that the receivable has a single deposit and that the final invoice uses the same VAT rate.')]);
        }

        $advance = self::resolveSource($receivable, $payment, $vatRate, $final->document_date->toDateString(), $final->id);

        if ($receivable->amount - $advance->total_amount !== $final->total_amount) {
            throw ValidationException::withMessages(['advance' => __('Check that the gross advance amount has already been deducted from the final invoice total.')]);
        }

        return $advance;
    }

    private static function resolveSource(Receivable $receivable, Payment $advance, int $vatRate, string $issueOn, ?int $finalId = null): FiscalDocument
    {
        $field = $finalId === null ? 'advanceSettlement' : 'advance';
        $documents = FiscalDocument::query()
            ->whereHas('links', fn ($links) => $links->where('payment_id', $advance->id))
            ->where('status', FiscalDocumentStatus::Active)
            ->where('direction', FiscalDocumentDirection::Issued)
            ->whereIn('type', [FiscalDocumentType::Invoice, FiscalDocumentType::InvoiceReceipt])
            ->limit(2)->get();
        if ($documents->count() !== 1) {
            throw ValidationException::withMessages([$field => __('Link a single active advance invoice to the payment in Fiscal documents before preparing the settlement.')]);
        }

        $document = $documents->sole();
        $allocations = $advance->allocations()->limit(2)->get();
        $allocation = $allocations->first();
        $nif = InvoiceXpressIssueTerms::nif($receivable->client?->vat_number);
        $allocatedOn = $allocation?->allocated_on->toDateString();
        $paidOn = $advance->paid_on->toDateString();
        $withholding = $document->total_amount > $advance->amount
            ? WithholdingReview::confirmedAdvanceEvidence($advance, $document)
            : null;
        $receiptId = $withholding?->fiscal_document_id
            ?? ($finalId === null ? self::separateCashReceiptId($document, $advance, $receivable->id, $issueOn) : null);

        if ($advance->account_id !== $receivable->account_id || $document->account_id !== $receivable->account_id
            || $nif === null || $nif !== InvoiceXpressIssueTerms::nif($document->party_tax_id)
            || (int) $advance->advance_client_id !== $receivable->client?->id
            || $document->currency !== 'EUR' || $document->document_date->toDateString() !== $paidOn
            || $allocations->count() !== 1 || $allocation?->receivable_id !== $receivable->id
            || $allocatedOn === null || $allocatedOn < $paidOn || $allocatedOn > $issueOn
            || $advance->amount <= 0 || $advance->amount !== $allocation->amount
            || $document->net_amount <= 0 || $document->net_amount > $document->total_amount
            || $document->net_amount + $document->vat_amount !== $document->total_amount
            || $document->vat_amount !== self::vat($document->net_amount, $vatRate)
            || $document->total_amount !== $advance->amount + ($withholding?->retained_amount ?? 0)
            || FiscalDocumentLink::query()->where('related_fiscal_document_id', $document->id)
                ->when($finalId !== null, fn ($links) => $links->where('fiscal_document_id', '!=', $finalId))
                ->when($receiptId !== null, fn ($links) => $links->where('fiscal_document_id', '!=', $receiptId))
                ->whereHas('fiscalDocument', fn ($documents) => $documents->where('status', FiscalDocumentStatus::Active)
                    ->when($finalId !== null && $withholding === null, fn ($documents) => $documents->where('type', '!=', FiscalDocumentType::Receipt)))
                ->exists()) {
            throw ValidationException::withMessages([$field => __('Confirm the client, dates, full payment allocation, VAT and income tax withholding on the advance invoice. Also check for an existing settlement or correction.')]);
        }

        return $document;
    }

    private static function separateCashReceiptId(FiscalDocument $original, Payment $payment, int $receivableId, string $issueOn): ?int
    {
        if ($original->type !== FiscalDocumentType::Invoice || $original->total_amount !== $payment->amount) {
            return null;
        }

        $receipts = $original->relatedDocuments()
            ->where('fiscal_documents.status', FiscalDocumentStatus::Active)
            ->where('fiscal_documents.type', FiscalDocumentType::Receipt)
            ->limit(2)->get();
        if ($receipts->count() !== 1) {
            return null;
        }

        $receipt = $receipts->sole();
        $link = $receipt->links;

        return $receipt->account_id === $original->account_id
            && $receipt->direction === FiscalDocumentDirection::Issued && $receipt->currency === 'EUR'
            && $receipt->net_amount === $original->net_amount && $receipt->vat_amount === $original->vat_amount
            && $receipt->total_amount === $original->total_amount
            && $receipt->document_date->gte($original->document_date) && $receipt->document_date->toDateString() <= $issueOn
            && ($receipt->party_tax_id === null || InvoiceXpressIssueTerms::nif($receipt->party_tax_id) === InvoiceXpressIssueTerms::nif($original->party_tax_id))
            && $link?->payment_id === $payment->id && $link->expense_id === null
            && in_array($link->receivable_id, [null, $receivableId], true)
            ? $receipt->id
            : null;
    }

    /**
     * @param  array{net: int, tax: int, total: int}  $service
     * @return array{reference: string, date: string, deduction: array{net: int, tax: int, total: int}, balance: array{net: int, tax: int, total: int}}
     */
    public static function amounts(FiscalDocument $advance, array $service, int $vatRate): array
    {
        $net = $service['net'] - $advance->net_amount;
        $tax = self::vat($net, $vatRate);

        if ($net < 0 || $tax !== $service['tax'] - $advance->vat_amount) {
            throw ValidationException::withMessages(['advanceSettlement' => __('The remaining net amount and VAT do not match to the cent. Review the advance and line rounding before issuing.')]);
        }

        return [
            'reference' => $advance->number,
            'date' => $advance->document_date->format('d/m/Y'),
            'deduction' => ['net' => $advance->net_amount, 'tax' => $advance->vat_amount, 'total' => $advance->total_amount],
            'balance' => ['net' => $net, 'tax' => $tax, 'total' => $net + $tax],
        ];
    }

    private static function vat(int $net, int $vatRate): int
    {
        return intdiv($net * $vatRate + 5000, 10000);
    }
}
