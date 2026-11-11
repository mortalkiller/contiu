<?php

namespace App\Actions;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\IntegrationOperationStatus;
use App\Enums\IntegrationRecordState;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLine;
use App\Models\FiscalDocumentLink;
use App\Models\IntegrationRecord;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\Retainer;
use App\Support\FiscalDocumentTotals;
use App\Support\Invoicing\Invoicing;
use App\Support\Invoicing\InvoicingRecords;
use App\Support\Invoicing\RemoteDocument;
use App\Support\Invoicing\RemoteDocumentState;
use App\Support\Invoicing\RemoteLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Brings into the app a document that was issued at the provider itself: its header, its lines
 * and, when the user points to it, the receivable it charges.
 */
final class ImportRemoteDocument
{
    public function handle(IntegrationRecord $entry, ?int $receivableId = null): FiscalDocument
    {
        abort_unless(Invoicing::canIssue(), 403);
        $provider = Invoicing::provider() ?? abort(409);

        if ($entry->record_id !== null || $entry->integration_connection_id !== Invoicing::connection()->getKey()) {
            throw ValidationException::withMessages(['receivable_id' => __('This document is already in the app.')]);
        }

        $remote = $provider->find($entry->remote_type, $entry->remote_id);

        if ($remote->state === RemoteDocumentState::Draft || $remote->number === null) {
            throw ValidationException::withMessages(['receivable_id' => __('Only issued documents can be imported.')]);
        }

        return DB::transaction(function () use ($entry, $remote, $receivableId): FiscalDocument {
            $entry = IntegrationRecord::query()->lockForUpdate()->findOrFail($entry->getKey());

            if ($entry->record_id !== null) {
                throw ValidationException::withMessages(['receivable_id' => __('This document is already in the app.')]);
            }

            $document = $this->store($remote);
            $targets = array_filter([
                'receivable_id' => $receivableId === null ? null : $this->receivable($remote, $receivableId)->getKey(),
                'related_fiscal_document_id' => $this->original($entry, $remote)?->getKey(),
            ]);

            if ($targets !== []) {
                $document->links()->create($targets);
            }

            InvoicingRecords::store($document, $remote, IntegrationRecordState::InSync);
            InvoicingRecords::log('imported', IntegrationOperationStatus::Done, __(':number imported', ['number' => $remote->number]), $document);

            return $document;
        });
    }

    /**
     * The receivables this document may be charging: same client, same amount and no invoice yet.
     *
     * @return Collection<int, Receivable>
     */
    public function suggestions(IntegrationRecord $entry): Collection
    {
        $taxId = $entry->remote['party_tax_id'] ?? null;
        $total = $entry->remote['total'] ?? null;

        if (blank($taxId) || ! is_int($total)) {
            return collect();
        }

        return $this->openReceivables($taxId)->where('amount', $total)->orderBy('due_on')->limit(5)->get();
    }

    private function store(RemoteDocument $remote): FiscalDocument
    {
        $document = new FiscalDocument;
        $document->forceFill([
            'type' => $remote->type,
            'direction' => FiscalDocumentDirection::Issued,
            'status' => $remote->state === RemoteDocumentState::Canceled ? FiscalDocumentStatus::Voided : FiscalDocumentStatus::Active,
            'number' => $remote->number,
            'series' => $remote->series,
            'atcud' => $remote->atcud,
            'origin' => Invoicing::providerLabel(),
            'document_date' => $remote->date,
            'due_date' => $remote->dueDate,
            'issued_at' => $remote->date,
            'currency' => 'EUR',
            'net_amount' => $remote->net,
            'vat_amount' => $remote->vat,
            'total_amount' => $remote->total,
            'withholding_amount' => $remote->withholding,
            'withholding_basis_points' => $remote->net > 0 ? (int) round($remote->withholding * 10000 / $remote->net) : 0,
            'party_name' => $remote->partyName,
            'party_tax_id' => $remote->partyTaxId,
            'client_id' => filled($remote->partyTaxId) ? Client::query()->where('vat_number', $remote->partyTaxId)->value('id') : null,
        ])->save();

        foreach ($remote->lines as $index => $line) {
            $document->lines()->create($this->line($line, $index));
        }

        return $document;
    }

    /** @return array<string, int|string> */
    private function line(RemoteLine $line, int $index): array
    {
        $amounts = FiscalDocumentTotals::line($line->quantity, $line->unitPrice, $line->discountBasisPoints, $line->vatBasisPoints);

        return [
            'sort' => $index,
            'description' => $line->description !== '' ? $line->description : __('Line :number', ['number' => $index + 1]),
            'quantity' => max(1, $line->quantity ?: FiscalDocumentLine::QUANTITY_SCALE),
            'unit' => $line->unit,
            'unit_price' => $line->unitPrice,
            'discount_basis_points' => $line->discountBasisPoints,
            'vat_basis_points' => $line->vatBasisPoints,
            'net_amount' => $amounts['net'],
            'vat_amount' => $amounts['vat'],
            'total_amount' => $amounts['total'],
        ];
    }

    private function receivable(RemoteDocument $remote, int $receivableId): Receivable
    {
        return $this->openReceivables((string) $remote->partyTaxId)->whereKey($receivableId)->first()
            ?? throw ValidationException::withMessages(['receivable_id' => __('Choose a receivable of the same client that has no invoice yet.')]);
    }

    /** @return Builder<Receivable> */
    private function openReceivables(string $taxId): Builder
    {
        $clients = Client::query()->where('vat_number', $taxId)->select('id');
        $invoiced = FiscalDocumentLink::query()
            ->whereNotNull('receivable_id')
            ->whereHas('fiscalDocument', fn (Builder $query) => $query
                ->where('direction', FiscalDocumentDirection::Issued)
                ->where('status', FiscalDocumentStatus::Active))
            ->select('receivable_id');

        return Receivable::query()
            ->excludingCancelled()
            ->whereHasMorph('billable', [Project::class, Retainer::class], fn (Builder $query) => $query->whereIn('client_id', $clients))
            ->whereNotIn('id', $invoiced);
    }

    /** The invoice a note or a receipt was issued against, when the app already has it. */
    private function original(IntegrationRecord $entry, RemoteDocument $remote): ?FiscalDocument
    {
        if ($remote->ownerRemoteId === null) {
            return null;
        }

        $owner = IntegrationRecord::query()
            ->where('integration_connection_id', $entry->integration_connection_id)
            ->where('remote_id', $remote->ownerRemoteId)
            ->whereNotNull('record_id')
            ->with('record')
            ->first()?->record;

        return $owner instanceof FiscalDocument ? $owner : null;
    }
}
