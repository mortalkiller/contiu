<?php

namespace App\Support;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Enums\PortugueseVatRegion;
use App\Exceptions\InvoiceXpressException;
use App\Models\Account;
use App\Models\FiscalDocument;
use App\Models\InvoiceXpressNoteAttempt;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class InvoiceXpressNoteIssue
{
    public function __construct(
        private readonly InvoiceXpressClient $client,
        private readonly InvoiceXpressImport $import,
        private readonly InvoiceXpressSandboxDrafts $drafts,
    ) {}

    public function original(int $originalId): array
    {
        abort_unless(InvoiceXpressIssueRepository::ownerCanIssue(), 403);

        return $this->readOriginal($originalId, $this->client->configuration()->subdomain, InvoiceXpressIssue::options()['draftOnly'] ?? false);
    }

    public function preview(array $input): array
    {
        abort_unless(InvoiceXpressIssueRepository::ownerCanIssue(), 403);
        $input = $this->validated($input);

        return $this->locked(function () use ($input): array {
            $subdomain = $this->client->configuration()->subdomain;
            $snapshot = $this->snapshot($input, $subdomain);

            return [...$snapshot, 'revision' => $this->revision($snapshot, $subdomain)];
        });
    }

    public function createDraft(array $input, string $revision): int
    {
        abort_unless(InvoiceXpressIssueRepository::ownerCanIssue(), 403);
        $input = $this->validated($input);

        return $this->locked(function () use ($input, $revision): int {
            $subdomain = $this->client->configuration()->subdomain;
            abort_unless(InvoiceXpressClient::sandboxDraftsAllowed($subdomain) && (InvoiceXpressIssue::options()['draftOnly'] ?? false), 403);
            $snapshot = $this->snapshot($input, $subdomain);
            if (! ($snapshot['draftOnly'] ?? false) || ! hash_equals($this->revision($snapshot, $subdomain), $revision)) {
                throw $this->invalidConfirmation();
            }

            $reference = 'Contiu draft '.$revision;
            $kind = $input['kind'];
            $original = $snapshot['original']['providerId'];
            $existing = array_values(array_filter($this->related($original, $subdomain),
                fn (array $row): bool => ($row['reference'] ?? null) === $reference));
            if (count($existing) > 1 || ($existing !== [] && ($existing[0]['type'] !== InvoiceXpressNoteTerms::Types[$kind]
                || $existing[0]['status'] !== 'draft'))) {
                throw $this->invalidConfirmation();
            }

            $id = $existing === [] ? $this->drafts->create(
                $subdomain, $kind, [...$this->providerPayload($snapshot['payload']), 'reference' => $reference],
                [$input['itemId']], $revision, $snapshot['totals'], $snapshot['withholding'] ?? null,
            ) : InvoiceXpressIssueTerms::positiveId($existing[0]['id'] ?? null);
            if ($id === null || $id === $original) {
                throw $this->invalidConfirmation();
            }

            $note = $this->read("/{$kind}s/{$id}.json", $kind, $subdomain);
            if (($note['status'] ?? null) !== 'draft' || ! $this->matchesRemote($snapshot, $note, $id, $reference, $subdomain)) {
                throw $this->invalidConfirmation();
            }

            return $id;
        });
    }

    public function confirm(array $input, string $revision): InvoiceXpressNoteAttempt
    {
        abort_unless(InvoiceXpressIssueRepository::ownerCanIssue(), 403);
        $input = $this->validated($input);

        $attempt = $this->locked(function () use ($input, $revision): InvoiceXpressNoteAttempt {
            $subdomain = $this->client->configuration()->subdomain;
            $this->requireCatalogueSafety(['draftOnly' => InvoiceXpressIssue::options()['draftOnly'] ?? false]);
            $existing = InvoiceXpressNoteAttempt::query()->where('revision', $revision)->first();
            if ($existing !== null) {
                if ($existing->subdomain !== $subdomain || $existing->snapshot['input'] !== $input
                    || (int) $existing->confirmed_by !== (int) auth()->id()) {
                    throw $this->invalidConfirmation();
                }

                return $existing;
            }

            if (InvoiceXpressNoteAttempt::query()->where('original_id', $input['originalId'])
                ->where('state', '!=', InvoiceXpressNoteAttempt::Finalized)->exists()) {
                throw InvoiceXpressNoteTerms::invalid('confirmation', __('Resolve the previous attempt for this invoice first.'));
            }

            $snapshot = $this->snapshot($input, $subdomain);
            $this->requireCatalogueSafety($snapshot);

            if (! hash_equals($this->revision($snapshot, $subdomain), $revision)) {
                throw $this->invalidConfirmation();
            }

            return tap(new InvoiceXpressNoteAttempt, fn (InvoiceXpressNoteAttempt $attempt) => $attempt->forceFill([
                'original_id' => $input['originalId'],
                'confirmed_by' => auth()->id(),
                'subdomain' => $subdomain,
                'revision' => $revision,
                'proprietary_uid' => (string) Str::uuid(),
                'snapshot' => $snapshot,
                'state' => InvoiceXpressNoteAttempt::Pending,
            ])->save());
        });

        return $this->recover($attempt->id);
    }

    public function recover(int $attemptId): InvoiceXpressNoteAttempt
    {
        abort_unless(InvoiceXpressIssueRepository::ownerCanIssue(), 403);

        $attempt = $this->locked(function () use ($attemptId): InvoiceXpressNoteAttempt {
            $attempt = $this->findAttempt($attemptId);
            if ($attempt->state !== InvoiceXpressNoteAttempt::Pending) {
                return $attempt;
            }

            $this->requireCatalogueSafety($attempt->snapshot);

            try {
                if (! $this->stillCurrent($attempt)) {
                    return $this->block($attempt);
                }

                $kind = $attempt->snapshot['input']['kind'];
                $payload = $this->providerPayload($attempt->snapshot['payload']);

                $response = $this->write($attempt, 'POST', "/{$kind}s.json", [
                    'proprietary_uid' => $attempt->proprietary_uid,
                    $kind => [...$payload, 'reference' => $this->reference($attempt)],
                ]);
            } catch (InvoiceXpressException) {
                return $attempt;
            }

            $id = InvoiceXpressIssueTerms::positiveId(data_get($response, "{$kind}.id"));
            if ($id === null) {
                return $attempt;
            }

            if ($id === $attempt->snapshot['original']['providerId']
                || InvoiceXpressNoteAttempt::query()->where('subdomain', $attempt->subdomain)->where('remote_id', $id)->exists()) {
                return $this->block($attempt);
            }

            $attempt->forceFill(['remote_id' => $id, 'state' => InvoiceXpressNoteAttempt::Draft])->save();

            return $attempt;
        });

        if ($attempt->state !== InvoiceXpressNoteAttempt::Draft) {
            return $attempt;
        }

        return $this->locked(function () use ($attemptId): InvoiceXpressNoteAttempt {
            $attempt = $this->findAttempt($attemptId);
            if ($attempt->state !== InvoiceXpressNoteAttempt::Draft) {
                return $attempt;
            }

            try {
                $note = $this->remoteNote($attempt);
                if (! $this->matches($attempt, $note)) {
                    return $this->block($attempt);
                }

                if (in_array($note['status'], InvoiceXpressNoteTerms::Issued, true)) {
                    return $this->recordFinal($attempt, $note);
                }

                if ($note['status'] !== 'draft' || ! $this->stillCurrent($attempt)) {
                    return $this->block($attempt);
                }

                $kind = $attempt->snapshot['input']['kind'];
                try {
                    $this->write($attempt, 'PUT', "/{$kind}s/{$attempt->remote_id}/change-state.json", [
                        'invoice' => ['state' => 'finalized'],
                    ]);
                } catch (InvoiceXpressException) {
                    // An empty or interrupted response may follow a successful state change.
                }

                $note = $this->remoteNote($attempt);
            } catch (InvoiceXpressException) {
                return $attempt;
            }

            if (! $this->matches($attempt, $note)) {
                return $this->block($attempt);
            }

            return match (true) {
                in_array($note['status'], InvoiceXpressNoteTerms::Issued, true) => $this->recordFinal($attempt, $note),
                $note['status'] === 'draft' => $attempt,
                default => $this->block($attempt),
            };
        });
    }

    private function validated(array $input): array
    {
        $input['reason'] = is_string($input['reason'] ?? null) ? trim($input['reason']) : $input['reason'] ?? null;
        $data = Validator::make($input, [
            'originalId' => ['required', 'integer', 'min:1'],
            'kind' => ['required', 'in:credit_note,debit_note'],
            'line' => ['required', 'integer', 'between:1,100'],
            'itemId' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'between:1,1000'],
            'reason' => ['required', 'string', 'max:255', 'not_regex:/[\x00-\x1f\x7f]/u'],
        ], [
            'originalId.*' => __('Choose the original invoice.'),
            'kind.*' => __('Choose the note type.'),
            'line.*' => __('Choose an invoice line.'),
            'itemId.*' => __('Choose an approved item.'),
            'quantity.*' => __('Enter a whole quantity from 1 to 1,000.'),
            'reason.*' => __('Enter a reason for the correction on one line, up to 255 characters.'),
        ])->validate();

        foreach (['originalId', 'line', 'itemId', 'quantity'] as $field) {
            $data[$field] = (int) $data[$field];
        }

        return $data;
    }

    private function readOriginal(int $originalId, string $subdomain, bool $draftOnly): array
    {
        $original = FiscalDocument::query()->with('invoiceXpressSnapshot')->find($originalId);
        abort_if($original === null, 404);
        $source = $original->invoiceXpressSnapshot;

        $resource = match ($original->type) {
            FiscalDocumentType::Invoice => ['invoice', 'Invoice'],
            FiscalDocumentType::InvoiceReceipt => ['invoice_receipt', 'InvoiceReceipt'],
            default => null,
        };

        if ($resource === null || $original->status !== FiscalDocumentStatus::Active
            || $original->direction !== FiscalDocumentDirection::Issued || $original->currency !== 'EUR'
            || $source?->subdomain !== $subdomain || ($source->source['provider_type'] ?? null) !== $resource[1]) {
            throw InvoiceXpressNoteTerms::invalid('originalId', __('Choose an active invoice or invoice receipt issued through the current InvoiceXpress connection.'));
        }

        $providerId = $source->provider_id;
        $invoice = $this->read("/{$resource[0]}s/{$providerId}.json", $resource[0], $subdomain);
        $client = is_array($invoice['client'] ?? null) ? $invoice['client'] : [];
        $clientId = InvoiceXpressIssueTerms::positiveId($client['id'] ?? null);
        $lines = InvoiceXpressNoteTerms::lines($invoice, $source->source['tax_lines'] ?? []);
        $gross = array_sum(array_column(array_column($lines, 'totals'), 'total'));
        $recordedWithholding = $source->source['withholding'] ?? null;
        $rate = $lines[0]['withholdingBasisPoints'] ?? 0;
        $withholding = $rate === 0 ? null : InvoiceXpressIssueTerms::withholdingAmounts($rate, $original->net_amount, $gross);

        if (InvoiceXpressIssueTerms::positiveId($invoice['id'] ?? null) !== $providerId
            || ($invoice['type'] ?? null) !== $resource[1] || ! in_array($invoice['status'] ?? null, InvoiceXpressNoteTerms::Issued, true)
            || ($invoice['inverted_sequence_number'] ?? null) !== $original->number
            || ($invoice['date'] ?? null) !== $original->document_date->format('d/m/Y') || $original->document_date->gt(today())
            || InvoiceXpressIssueTerms::hundredths($invoice['before_taxes'] ?? null) !== $original->net_amount
            || InvoiceXpressIssueTerms::hundredths($invoice['taxes'] ?? null) !== $original->vat_amount
            || $gross !== $original->total_amount
            || ($recordedWithholding !== null && $recordedWithholding !== $withholding)
            || $clientId === null || $original->party_tax_id === null) {
            throw InvoiceXpressNoteTerms::invalid('originalId', __('The original InvoiceXpress invoice does not match the Contiu record.'));
        }

        return [
            'id' => $originalId,
            'providerId' => $providerId,
            ...($resource[1] === 'InvoiceReceipt' ? ['providerType' => 'InvoiceReceipt'] : []),
            'number' => $original->number,
            'customer' => InvoiceXpressNoteTerms::customer($client, $clientId, $original->party_tax_id, $invoice['tax_exemption'] ?? null, $draftOnly),
            'lines' => $lines,
        ];
    }

    private function snapshot(array $input, string $subdomain, ?InvoiceXpressNoteAttempt $attempt = null): array
    {
        $options = InvoiceXpressIssue::options(forNewIssue: $attempt === null);
        if ($options === null || ! in_array($input['itemId'], $options['itemIds'], true)) {
            throw InvoiceXpressNoteTerms::invalid('itemId', __('Choose an item approved in the InvoiceXpress issuance settings.'));
        }

        $draftOnly = $options['draftOnly'] ?? false;
        $original = $this->readOriginal($input['originalId'], $subdomain, $draftOnly);
        if (($original['providerType'] ?? null) === 'InvoiceReceipt' && $input['kind'] !== 'credit_note') {
            throw InvoiceXpressNoteTerms::invalid('kind', __('Only a credit note can be issued here for an invoice receipt.'));
        }
        $lineIndex = $input['line'] - 1;
        $line = $original['lines'][$lineIndex] ?? null;
        if ($line === null) {
            throw InvoiceXpressNoteTerms::invalid('line', __('Choose a line from the original invoice.'));
        }
        $lineTax = $line['tax'];
        $region = $lineTax['region'] ?? PortugueseVatRegion::Mainland->value;
        if (! isset($lineTax['region']) && $lineTax['basisPoints'] !== 0
            && PortugueseVatRegion::Mainland->categoryFor($lineTax['basisPoints']) === null) {
            throw InvoiceXpressNoteTerms::invalid('line', __('The original line must identify the VAT region before a note can be issued.'));
        }

        InvoiceXpressNoteTerms::originalLineIndex($original['lines'], $line);

        $customerId = $original['customer']['id'];
        $customer = InvoiceXpressNoteTerms::customer(
            $this->read("/clients/{$customerId}.json", 'client', $subdomain), $customerId, $original['customer']['nif'], $line['exemptionReason'] ?? null, $draftOnly,
        );
        $itemId = $input['itemId'];
        $remoteItem = $this->read("/items/{$itemId}.json", 'item', $subdomain);
        $item = InvoiceXpressIssueTerms::item($remoteItem, $itemId);
        unset($item['id']);
        $tax = InvoiceXpressIssueTerms::tax($this->read('/taxes.json', 'taxes', $subdomain), $lineTax['name'], $region, $lineTax['basisPoints'], $draftOnly);
        $itemTax = is_array($remoteItem['tax'] ?? null) ? $remoteItem['tax'] : [];

        if ($item !== $line['article'] || $customer !== $original['customer']
            || $tax['id'] !== $lineTax['id']
            || (array_key_exists('id', $itemTax) && InvoiceXpressIssueTerms::positiveId($itemTax['id']) !== $tax['id'])
            || ! InvoiceXpressIssueTerms::matchesTaxRegion($itemTax['region'] ?? null, $tax['region'])
            || (isset($lineTax['code'], $itemTax['code']) && $itemTax['code'] !== '' && $itemTax['code'] !== $lineTax['code'])
            || ($itemTax['name'] ?? null) !== $tax['name']
            || InvoiceXpressIssueTerms::hundredths($itemTax['value'] ?? null) !== $tax['basisPoints']
            || ! in_array(InvoiceXpressIssueTerms::hundredths($remoteItem['retention'] ?? 0), [0, $line['withholdingBasisPoints'] ?? 0], true)) {
            throw InvoiceXpressNoteTerms::invalid('itemId', __('The customer, approved item and VAT must match the original invoice.'));
        }

        $sequenceId = $options['sequenceId'];
        $sequence = InvoiceXpressNoteTerms::sequence($this->read("/sequences/{$sequenceId}.json", 'sequence', $subdomain), $sequenceId, $input['kind'], $draftOnly);
        $history = $this->history($original, $subdomain, $attempt, $draftOnly);
        $remaining = ['quantity' => $line['quantity'], 'total' => $line['totals']['total']];
        $available = $line['totals'];
        $originalWithholding = InvoiceXpressNoteTerms::withholding($line);
        if ($originalWithholding !== null) {
            $available['retained'] = $originalWithholding['retained'];
            $available['cash'] = $originalWithholding['cash'];
        }

        foreach ($history as $note) {
            if ($note['type'] !== InvoiceXpressNoteTerms::Types[$input['kind']]) {
                continue;
            }

            foreach ($note['lines'] as $adjustment) {
                if ($adjustment['index'] === $lineIndex) {
                    $remaining['quantity'] -= $adjustment['quantity'];
                    foreach (['net', 'tax', 'total'] as $key) {
                        $available[$key] -= $adjustment['totals'][$key];
                    }
                    if ($originalWithholding !== null) {
                        $available['retained'] -= $adjustment['withholding']['retained'];
                        $available['cash'] -= $adjustment['withholding']['cash'];
                    }
                }
            }
        }

        $remaining['total'] = $available['total'];
        if ($originalWithholding !== null) {
            $remaining['retained'] = $available['retained'];
            $remaining['cash'] = $available['cash'];
        }
        $discountBasisPoints = $line['discountBasisPoints'] ?? 0;
        $totals = InvoiceXpressIssueTerms::totals($item['unitCents'], $input['quantity'], $tax['basisPoints'], $discountBasisPoints);
        $withholding = InvoiceXpressNoteTerms::withholding($line, $totals);
        if ($input['kind'] === 'debit_note' && $totals['total'] === 0) {
            throw InvoiceXpressNoteTerms::invalid('quantity', __('The debit note total must be positive.'));
        }

        if ($input['quantity'] > $remaining['quantity'] || $totals['total'] > $available['total']
            || $totals['net'] > $available['net'] || $totals['tax'] > $available['tax']
            || ($withholding !== null && ($withholding['retained'] > $available['retained'] || $withholding['cash'] > $available['cash']))) {
            throw InvoiceXpressNoteTerms::invalid('quantity', __('The quantity or amount exceeds the verifiable balance of this line for this note type.'));
        }

        $date = today()->format('d/m/Y');

        return [
            'input' => $input,
            ...($draftOnly ? ['draftOnly' => true] : []),
            'original' => $original,
            'history' => $history,
            'remaining' => $remaining,
            'totals' => $totals,
            ...($withholding === null ? [] : ['withholding' => $withholding]),
            'sequence' => $sequence,
            'payload' => [
                'date' => $date,
                'due_date' => $date,
                'observations' => $input['reason'],
                'sequence_id' => (string) $sequence['id'],
                'currency_code' => 'EUR',
                'owner_invoice_id' => $original['providerId'],
                ...(isset($line['exemptionReason']) ? ['tax_exemption_reason' => $line['exemptionReason']] : []),
                ...($withholding === null ? [] : ['retention_basis_points' => $withholding['basisPoints']]),
                'client' => ['id' => $customerId],
                'items' => [[
                    'name' => $item['name'],
                    'description' => $item['description'],
                    'unit' => $item['unit'],
                    'unit_price_cents' => $item['unitCents'],
                    'quantity' => $input['quantity'],
                    'tax' => ['name' => $tax['name']],
                    ...($discountBasisPoints === 0 ? [] : ['discount_basis_points' => $discountBasisPoints]),
                    ...(isset($line['lineRetentionBasisPoints']) ? ['retention_basis_points' => $line['lineRetentionBasisPoints']] : []),
                ]],
            ],
        ];
    }

    private function history(array $original, string $subdomain, ?InvoiceXpressNoteAttempt $attempt, bool $draftOnly): array
    {
        $history = [];
        $seen = [];
        $ownNote = false;

        foreach ($this->related($original['providerId'], $subdomain) as $note) {
            if (! in_array($note['type'], InvoiceXpressNoteTerms::Types, true)) {
                continue;
            }

            $id = InvoiceXpressIssueTerms::positiveId($note['id'] ?? null);
            $owner = $note['owner_invoice_id'] ?? null;
            if ($id === null || $id === $original['providerId'] || isset($seen[$id])
                || ($owner !== null && InvoiceXpressIssueTerms::positiveId($owner) !== $original['providerId'])) {
                throw $this->invalidConfirmation();
            }
            $seen[$id] = true;

            if ($attempt !== null && ($note['reference'] ?? null) === $this->reference($attempt)) {
                if ($ownNote || ! in_array($note['status'], ['draft', ...InvoiceXpressNoteTerms::Issued], true)
                    || ! $this->matches($attempt, $note, $id, related: true)) {
                    throw $this->invalidConfirmation();
                }
                $ownNote = true;

                continue;
            }

            if ($note['status'] === 'canceled' || ($draftOnly && $note['status'] === 'draft')) {
                continue;
            }
            if (! in_array($note['status'], InvoiceXpressNoteTerms::Issued, true)) {
                throw InvoiceXpressNoteTerms::invalid('confirmation', __('A linked note has no verifiable status or balance in InvoiceXpress.'));
            }

            $client = is_array($note['client'] ?? null) ? $note['client'] : [];
            if (InvoiceXpressNoteTerms::customer($client, $original['customer']['id'], $original['customer']['nif'], $original['lines'][0]['exemptionReason'] ?? null, $draftOnly) !== $original['customer']) {
                throw $this->invalidConfirmation();
            }

            $lines = [];
            foreach (InvoiceXpressNoteTerms::lines($note) as $line) {
                $withholding = InvoiceXpressNoteTerms::withholding($line);
                $lines[] = [
                    'index' => InvoiceXpressNoteTerms::originalLineIndex($original['lines'], $line),
                    'quantity' => $line['quantity'],
                    'totals' => $line['totals'],
                    ...($withholding === null ? [] : ['withholding' => $withholding]),
                ];
            }

            $history[$id] = ['type' => $note['type'], 'lines' => $lines];
        }

        $local = FiscalDocument::query()->findOrFail($original['id']);
        $related = $local->relatedDocuments()->where('status', FiscalDocumentStatus::Active)
            ->whereIn('type', [FiscalDocumentType::CreditNote, FiscalDocumentType::DebitNote])->with('invoiceXpressSnapshot')->get();

        foreach ($related as $note) {
            $source = $note->invoiceXpressSnapshot;
            $remote = $source?->subdomain === $subdomain ? $history[$source->provider_id] ?? null : null;
            if ($remote === null || $remote['type'] !== InvoiceXpressNoteTerms::Types[$note->type->value]
                || array_sum(array_column(array_column($remote['lines'], 'totals'), 'total')) !== $note->total_amount) {
                throw InvoiceXpressNoteTerms::invalid('confirmation', __('A note in Contiu has a balance that has yet to be confirmed in InvoiceXpress.'));
            }
        }

        ksort($history);

        return $history;
    }

    private function matches(InvoiceXpressNoteAttempt $attempt, array $note, ?int $id = null, bool $related = false): bool
    {
        $id ??= $attempt->remote_id;

        return $id !== null && ($attempt->remote_id === null || $id === $attempt->remote_id)
            && $this->matchesRemote($attempt->snapshot, $note, $id, $this->reference($attempt), $attempt->subdomain, $related);
    }

    public function matchesRemote(array $snapshot, array $note, int $id, string $reference, string $subdomain, bool $related = false): bool
    {
        $original = $snapshot['original']['providerId'];
        if (($note['owner_invoice_id'] ?? null) === null && ! $related) {
            $matches = array_values(array_filter($this->related($original, $subdomain),
                fn (array $row): bool => InvoiceXpressIssueTerms::positiveId($row['id'] ?? null) === $id));
            $related = count($matches) === 1
                && $matches[0]['type'] === InvoiceXpressNoteTerms::Types[$snapshot['input']['kind']]
                && $matches[0]['status'] === ($note['status'] ?? null)
                && (($matches[0]['owner_invoice_id'] ?? null) === null
                    || InvoiceXpressIssueTerms::positiveId($matches[0]['owner_invoice_id']) === $original);
        }

        return InvoiceXpressNoteTerms::matchesDocument($note, $snapshot, $id, $reference, $related);
    }

    private function recordFinal(InvoiceXpressNoteAttempt $attempt, array $note): InvoiceXpressNoteAttempt
    {
        $original = $attempt->original_id === null ? null : FiscalDocument::query()->find($attempt->original_id);
        $sequence = $attempt->snapshot['sequence'];
        $series = preg_quote($sequence['name'], '#');
        $number = $note['inverted_sequence_number'] ?? null;
        $atcud = $note['atcud'] ?? null;
        if ($original === null || ! is_string($number) || ! preg_match("#\A{$series}/([1-9][0-9]{0,18})\z#", $number, $parts)
            || $atcud !== $sequence['validationCode'].'-'.$parts[1]) {
            return $this->block($attempt);
        }

        try {
            $document = DB::transaction(function () use ($attempt, $note, $original): FiscalDocument {
                $originalLine = $attempt->snapshot['original']['lines'][$attempt->snapshot['input']['line'] - 1];
                $review = $this->import->reviewInvoice($note, $attempt->subdomain);
                if (! in_array($review['state'], ['new', 'same'], true) || $review['withholding'] !== ($attempt->snapshot['withholding'] ?? null)) {
                    throw $this->invalidConfirmation();
                }

                $expected = $review['after'];
                if ($expected['total_amount'] !== $attempt->snapshot['totals']['total']
                    || $expected['net_amount'] !== $attempt->snapshot['totals']['net']
                    || $expected['vat_amount'] !== $attempt->snapshot['totals']['tax']) {
                    throw $this->invalidConfirmation();
                }
                $document = $this->import->confirmInvoice(
                    $review['invoice'], expectedSubdomain: $attempt->subdomain, verifiedLineTax: $originalLine['tax'],
                );
                if ($document->type->value !== $expected['type'] || $document->direction->value !== $expected['direction']
                    || $document->status->value !== $expected['status'] || $document->number !== $expected['number']
                    || $document->currency !== $expected['currency'] || $document->document_date->toDateString() !== $expected['document_date']
                    || $document->net_amount !== $expected['net_amount'] || $document->vat_amount !== $expected['vat_amount']
                    || $document->total_amount !== $expected['total_amount'] || $document->party_tax_id !== $expected['party_tax_id']) {
                    throw $this->invalidConfirmation();
                }

                $link = $document->links()->first();
                if ($link === null) {
                    $document->links()->create(['related_fiscal_document_id' => $original->id]);
                } elseif ((int) $link->related_fiscal_document_id !== $original->id || $link->payment_id !== null
                    || $link->receivable_id !== null || $link->expense_id !== null) {
                    throw $this->invalidConfirmation();
                }

                return $document;
            });
        } catch (ValidationException) {
            return $this->block($attempt);
        }

        $attempt->forceFill([
            'state' => InvoiceXpressNoteAttempt::Finalized,
            'fiscal_document_id' => $document->id,
            'document_reference' => $number,
            'atcud' => $atcud,
        ])->save();

        return $attempt;
    }

    private function stillCurrent(InvoiceXpressNoteAttempt $attempt): bool
    {
        try {
            if ($attempt->original_id === null) {
                return false;
            }

            $current = $this->snapshot($attempt->snapshot['input'], $attempt->subdomain, $attempt);
            if ($attempt->snapshot === $current) {
                return true;
            }

            $current['payload'] = $this->decimalPayload($current['payload']);

            return $attempt->snapshot === $current;
        } catch (ValidationException) {
            return false;
        }
    }

    public function providerPayload(array $payload): array
    {
        $payload = $this->decimalPayload($payload);
        foreach (['discount', 'retention'] as $rate) {
            if (isset($payload['items'][0][$rate])) {
                $payload['items'][0][$rate] = (float) $payload['items'][0][$rate];
            }
        }

        return $payload;
    }

    private function decimalPayload(array $payload): array
    {
        $item = [];
        foreach ($payload['items'][0] as $field => $value) {
            match ($field) {
                'unit_price_cents' => $item['unit_price'] = sprintf('%d.%02d', intdiv($value, 100), $value % 100),
                'discount_basis_points' => $item['discount'] = sprintf('%d.%02d', intdiv($value, 100), $value % 100),
                'retention_basis_points' => $item['retention'] = sprintf('%d.%02d', intdiv($value, 100), $value % 100),
                default => $item[$field] = $value,
            };
        }
        $payload['items'][0] = $item;
        if (isset($payload['retention_basis_points'])) {
            $rate = $payload['retention_basis_points'];
            $payload['retention'] = sprintf('%d.%02d', intdiv($rate, 100), $rate % 100);
            unset($payload['retention_basis_points']);
        }

        return $payload;
    }

    private function remoteNote(InvoiceXpressNoteAttempt $attempt): array
    {
        $kind = $attempt->snapshot['input']['kind'];

        return $this->read("/{$kind}s/{$attempt->remote_id}.json", $kind, $attempt->subdomain);
    }

    private function related(int $providerId, string $subdomain): array
    {
        $documents = $this->read("/document/{$providerId}/related_documents.json", 'documents', $subdomain);

        if (! array_is_list($documents) || array_filter($documents, fn (mixed $row): bool => ! is_array($row)
            || ! is_string($row['type'] ?? null) || ! is_string($row['status'] ?? null)) !== []) {
            throw new InvoiceXpressException(__('Could not verify the list of related documents.'));
        }

        return $documents;
    }

    private function read(string $path, string $key, string $subdomain): array
    {
        $data = $this->client->request('GET', $path, expectedSubdomain: $subdomain)[$key] ?? null;

        return is_array($data) ? $data : throw new InvoiceXpressException(__('InvoiceXpress returned incomplete data.'));
    }

    private function findAttempt(int $id): InvoiceXpressNoteAttempt
    {
        $attempt = InvoiceXpressNoteAttempt::query()->lockForUpdate()->findOrFail($id);
        if ($this->client->configuration()->subdomain !== $attempt->subdomain) {
            throw InvoiceXpressNoteTerms::invalid('confirmation', __('Restore the original InvoiceXpress connection to verify the attempt.'));
        }

        return $attempt;
    }

    private function locked(Closure $work): mixed
    {
        return DB::transaction(function () use ($work): mixed {
            Account::query()->lockForUpdate()->findOrFail(CurrentAccount::id());
            abort_unless(InvoiceXpressIssueRepository::ownerCanIssue(), 403);

            return $work();
        });
    }

    private function requireCatalogueSafety(array $snapshot = []): void
    {
        if ($snapshot['draftOnly'] ?? false) {
            throw InvoiceXpressNoteTerms::invalid('confirmation', __('The selected series only allows draft creation.'));
        }
        if (! InvoiceXpressIssue::canWriteDocuments()) {
            throw InvoiceXpressNoteTerms::invalid('confirmation', __('Issuance is pending validation of the InvoiceXpress catalog.'));
        }
        if (isset($snapshot['withholding']) && ! InvoiceXpressIssue::canWriteWithholding()) {
            throw InvoiceXpressNoteTerms::invalid('confirmation', __('Issuance is pending validation of withholding tax in the InvoiceXpress sandbox.'));
        }
    }

    private function write(InvoiceXpressNoteAttempt $attempt, string $method, string $path, array $payload): array
    {
        $this->requireCatalogueSafety($attempt->snapshot);

        return $this->client->request($method, $path, payload: $payload, expectedSubdomain: $attempt->subdomain);
    }

    private function revision(array $snapshot, string $subdomain): string
    {
        return hash_hmac('sha256', json_encode([CurrentAccount::id(), auth()->id(), $subdomain, $snapshot], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function reference(InvoiceXpressNoteAttempt $attempt): string
    {
        return 'Contiu '.$attempt->proprietary_uid;
    }

    private function block(InvoiceXpressNoteAttempt $attempt): InvoiceXpressNoteAttempt
    {
        $attempt->forceFill(['state' => InvoiceXpressNoteAttempt::Blocked])->save();

        return $attempt;
    }

    private function invalidConfirmation(): ValidationException
    {
        return InvoiceXpressNoteTerms::invalid('confirmation', __('The details have changed or could not be verified. Review the preview or the recorded attempt.'));
    }
}
