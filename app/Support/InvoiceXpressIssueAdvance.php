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
    public const string ProviderBlock = 'A emissão do acerto pela API está bloqueada até o InvoiceXpress confirmar como deduzir o adiantamento e referenciar a fatura original sem alterar o catálogo.';

    public static function source(Receivable $receivable, Payment $advance, int $vatRate, string $issueOn): FiscalDocument
    {
        $documents = FiscalDocument::query()
            ->whereHas('links', fn ($links) => $links->where('payment_id', $advance->id))
            ->where('status', FiscalDocumentStatus::Active)
            ->where('direction', FiscalDocumentDirection::Issued)
            ->whereIn('type', [FiscalDocumentType::Invoice, FiscalDocumentType::InvoiceReceipt])
            ->limit(2)->get();
        $document = $documents->first();

        if ($documents->count() !== 1) {
            throw ValidationException::withMessages(['advanceSettlement' => 'Liga ao recebimento uma única fatura de adiantamento ativa em Documentos fiscais antes de preparar o acerto.']);
        }

        $nif = InvoiceXpressIssueTerms::nif($receivable->client?->vat_number);
        $allocatedOn = substr((string) $advance->pivot->allocated_on, 0, 10);
        $paidOn = $advance->paid_on->toDateString();

        if ($nif === null || $nif !== InvoiceXpressIssueTerms::nif($document->party_tax_id)
            || (int) $advance->advance_client_id !== $receivable->client?->id
            || $document->currency !== 'EUR' || $document->document_date->toDateString() !== $paidOn
            || $allocatedOn < $paidOn || $allocatedOn > $issueOn
            || $advance->amount <= 0 || $advance->amount !== (int) $advance->pivot->amount
            || $advance->allocations()->where('receivable_id', '!=', $receivable->id)->exists()
            || $advance->amount !== $document->total_amount
            || $document->net_amount <= 0 || $document->net_amount > $document->total_amount
            || $document->net_amount + $document->vat_amount !== $document->total_amount
            || $document->vat_amount !== self::vat($document->net_amount, $vatRate)
            || FiscalDocumentLink::query()->where('related_fiscal_document_id', $document->id)
                ->whereHas('fiscalDocument', fn ($documents) => $documents->where('status', FiscalDocumentStatus::Active))
                ->exists()) {
            throw ValidationException::withMessages(['advanceSettlement' => 'Confirma o cliente, a data, a afetação integral do recebimento e o IVA da fatura do adiantamento. Revê também se já existe um acerto ou uma retificação associada.']);
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
            throw ValidationException::withMessages(['advanceSettlement' => 'A base e o IVA remanescentes não coincidem ao cêntimo. Revê o adiantamento e o arredondamento das linhas antes de emitir.']);
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
