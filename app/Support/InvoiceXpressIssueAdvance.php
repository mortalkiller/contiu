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
    // The invoice API documents catalogue upserts by name, but no catalogue-safe way to
    // represent an advance deduction and identify its original invoice on the final invoice.
    public const string ProviderBlock = 'Issuing the settlement through the API is blocked until InvoiceXpress confirms how to deduct the advance and reference the original invoice without changing the catalog.';

    public static function source(Receivable $receivable, Payment $advance, int $vatRate, string $issueOn): FiscalDocument
    {
        return self::resolveSource($receivable, $advance, $vatRate, $issueOn);
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
                ->when($withholding !== null, fn ($links) => $links->where('fiscal_document_id', '!=', $withholding->fiscal_document_id))
                ->whereHas('fiscalDocument', fn ($documents) => $documents->where('status', FiscalDocumentStatus::Active)
                    ->when($finalId !== null && $withholding === null, fn ($documents) => $documents->where('type', '!=', FiscalDocumentType::Receipt)))
                ->exists()) {
            throw ValidationException::withMessages([$field => __('Confirm the client, dates, full payment allocation, VAT and income tax withholding on the advance invoice. Also check for an existing settlement or correction.')]);
        }

        return $document;
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
