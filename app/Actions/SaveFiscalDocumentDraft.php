<?php

namespace App\Actions;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Enums\IntegrationOperationStatus;
use App\Enums\IntegrationRecordState;
use App\Exceptions\InvoicingProviderException;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Support\FiscalDocumentRules;
use App\Support\FiscalDocumentTotals;
use App\Support\Invoicing\Invoicing;
use App\Support\Invoicing\InvoicingRecords;
use App\Support\Invoicing\RemoteDocument;
use App\Support\Invoicing\RemoteReferences;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves a document that is still being written: header, lines and the amounts they add up to. The
 * app is where the document lives; right after saving, the draft is created at the invoicing
 * provider too, so it can be checked there before it is issued.
 */
final class SaveFiscalDocumentDraft
{
    /** The header fields the form fills in as they are. */
    private const array HEADER = [
        'document_date', 'due_date', 'client_id', 'withholding_basis_points', 'vat_exemption_reason', 'client_reference',
        'notes', 'reason', 'receivable_effect', 'payment', 'transport',
    ];

    /** Why the provider refused the draft the last time it was saved, when it did. */
    public ?string $sendError = null;

    /**
     * Saves the draft, then sends it to the provider.
     *
     * The draft is kept even when the provider refuses it: `$sendError` then says why, and
     * saving again tries once more.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?FiscalDocument $draft = null): FiscalDocument
    {
        abort_unless(Invoicing::canIssue(), 403);
        $provider = Invoicing::provider() ?? throw ValidationException::withMessages(['type' => __('Connect an invoicing provider to create documents here.')]);
        ['data' => $data, 'totals' => $totals] = FiscalDocumentRules::validate($data, $draft);

        if (! $provider->capabilities()->supports($data['type'])) {
            throw ValidationException::withMessages(['type' => __(':provider does not issue this kind of document.', ['provider' => $provider::label()])]);
        }

        $document = DB::transaction(fn (): FiscalDocument => $this->store($data, $totals, $draft));
        $this->sendError = null;

        try {
            $this->send($document);
        } catch (InvoicingProviderException $exception) {
            $this->sendError = $exception->getMessage();
        }

        return $document;
    }

    /** Creates the draft at the provider, or brings the one it has up to date, and records how it compares. */
    public function send(FiscalDocument $document): ?RemoteDocument
    {
        // A receipt has no draft at a provider: it only exists once it is issued against its invoice.
        if ($document->type === FiscalDocumentType::Receipt) {
            return null;
        }

        try {
            $remote = Invoicing::provider()->saveDraft($document->load(['lines.article', 'client.structuredAddress']), $this->references($document));
        } catch (InvoicingProviderException $exception) {
            InvoicingRecords::log('draft_saved', IntegrationOperationStatus::Failed, __('Draft refused'), $document, $exception->getMessage());

            throw $exception;
        }

        $this->adoptRounding($document, $remote);
        $state = $remote->matches($document->net_amount, $document->vat_amount, $document->total_amount) ? IntegrationRecordState::InSync : IntegrationRecordState::Differs;
        InvoicingRecords::store($document, $remote, $state);
        InvoicingRecords::log('draft_saved', IntegrationOperationStatus::Done, __('Draft created at the provider'), $document);

        return $remote;
    }

    /** @param array<string, mixed> $data */
    private function store(array $data, FiscalDocumentTotals $totals, ?FiscalDocument $draft): FiscalDocument
    {
        $document = $draft === null ? new FiscalDocument : FiscalDocument::query()->lockForUpdate()->findOrFail($draft->getKey());

        if ($document->exists && ! $document->isDraft()) {
            throw ValidationException::withMessages(['type' => __('An issued document cannot be changed. Correct it with a credit note.')]);
        }

        $client = isset($data['client_id']) ? Client::query()->findOrFail($data['client_id']) : null;

        $document->fill([
            ...Arr::only($data, self::HEADER),
            'type' => $data['type'],
            'direction' => FiscalDocumentDirection::Issued,
            'status' => FiscalDocumentStatus::Draft,
            'origin' => Invoicing::providerLabel(),
            'currency' => 'EUR',
            'party_name' => $client?->name ?? ($data['party_name'] ?? null),
            'party_tax_id' => $client?->vat_number ?? ($data['party_tax_id'] ?? null),
            'withholding_basis_points' => (int) ($data['withholding_basis_points'] ?? 0),
            'discount_amount' => $totals->discount,
            'net_amount' => $totals->net,
            'vat_amount' => $totals->vat,
            'total_amount' => $totals->total,
            'withholding_amount' => $totals->withholding,
        ])->save();

        $document->lines()->delete();

        foreach (array_values($data['lines'] ?? []) as $index => $line) {
            $document->lines()->create([
                ...$line,
                'sort' => $index,
                'discount_basis_points' => (int) ($line['discount_basis_points'] ?? 0),
                'vat_basis_points' => (int) ($line['vat_basis_points'] ?? 0),
                'net_amount' => $totals->lines[$index]['net'],
                'vat_amount' => $totals->lines[$index]['vat'],
                'total_amount' => $totals->lines[$index]['total'],
            ]);
        }

        $targets = array_filter(['receivable_id' => $data['receivable_id'] ?? null, 'related_fiscal_document_id' => $data['original_id'] ?? null]);

        if ($targets !== []) {
            $document->links()->updateOrCreate([], ['receivable_id' => null, 'related_fiscal_document_id' => null, ...$targets]);
        } else {
            $document->links()->first()?->delete();
        }

        return $document;
    }

    private function references(FiscalDocument $document): RemoteReferences
    {
        $original = $document->links()->first()?->relatedFiscalDocument;

        // An article billed for the first time is created at the provider, so the line finds it there by name.
        $document->lines->pluck('article')->filter()->unique('id')->each(InvoicingRecords::articleId(...));

        return new RemoteReferences(
            document: InvoicingRecords::remoteId($document),
            client: $document->client === null ? null : InvoicingRecords::clientId($document->client),
            owner: $original === null ? null : InvoicingRecords::remoteId($original),
        );
    }

    /**
     * Certified software may round VAT a cent differently on a document with many lines. When the
     * net amount is the same and VAT is within a cent per line, the provider's figure is the one
     * that will be on the document, so the app takes it.
     */
    private function adoptRounding(FiscalDocument $document, RemoteDocument $remote): void
    {
        $difference = abs($remote->vat - $document->vat_amount);

        if ($remote->net === $document->net_amount && $difference > 0 && $difference <= $document->lines->count()) {
            $document->forceFill(['vat_amount' => $remote->vat, 'total_amount' => $remote->total])->save();
        }
    }
}
