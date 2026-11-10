<?php

namespace App\Support;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
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
    ) {}

    public function original(int $originalId): array
    {
        abort_unless(InvoiceXpressIssueRepository::ownerCanIssue(), 403);

        return $this->readOriginal($originalId, $this->client->configuration()->subdomain);
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

    public function confirm(array $input, string $revision): InvoiceXpressNoteAttempt
    {
        abort_unless(InvoiceXpressIssueRepository::ownerCanIssue(), 403);
        $input = $this->validated($input);

        $attempt = $this->locked(function () use ($input, $revision): InvoiceXpressNoteAttempt {
            $subdomain = $this->client->configuration()->subdomain;
            $this->requireCatalogueSafety();
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
                throw InvoiceXpressNoteTerms::invalid('confirmation', 'Resolve primeiro a tentativa anterior desta fatura.');
            }

            $snapshot = $this->snapshot($input, $subdomain);

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

            $this->requireCatalogueSafety();

            try {
                if (! $this->stillCurrent($attempt)) {
                    return $this->block($attempt);
                }

                $kind = $attempt->snapshot['input']['kind'];
                $response = $this->write($attempt, 'POST', "/{$kind}s.json", [
                    'proprietary_uid' => $attempt->proprietary_uid,
                    $kind => [...$attempt->snapshot['payload'], 'reference' => $this->reference($attempt)],
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
            'originalId.*' => 'Escolhe a fatura original.',
            'kind.*' => 'Escolhe o tipo de nota.',
            'line.*' => 'Escolhe uma linha da fatura.',
            'itemId.*' => 'Escolhe um artigo aprovado.',
            'quantity.*' => 'Indica uma quantidade inteira de 1 a 1 000.',
            'reason.*' => 'Indica o motivo da correção em até 255 caracteres numa só linha.',
        ])->validate();

        foreach (['originalId', 'line', 'itemId', 'quantity'] as $field) {
            $data[$field] = (int) $data[$field];
        }

        return $data;
    }

    private function readOriginal(int $originalId, string $subdomain): array
    {
        $original = FiscalDocument::query()->with('invoiceXpressSnapshot')->find($originalId);
        abort_if($original === null, 404);
        $source = $original->invoiceXpressSnapshot;

        if ($original->type !== FiscalDocumentType::Invoice || $original->status !== FiscalDocumentStatus::Active
            || $original->direction !== FiscalDocumentDirection::Issued || $original->currency !== 'EUR'
            || $source?->subdomain !== $subdomain || ($source->source['provider_type'] ?? null) !== 'Invoice') {
            throw InvoiceXpressNoteTerms::invalid('originalId', 'Escolhe uma fatura ativa emitida na ligação atual do InvoiceXpress.');
        }

        $providerId = $source->provider_id;
        $invoice = $this->read("/invoices/{$providerId}.json", 'invoice', $subdomain);
        $client = is_array($invoice['client'] ?? null) ? $invoice['client'] : [];
        $clientId = InvoiceXpressIssueTerms::positiveId($client['id'] ?? null);

        if (InvoiceXpressIssueTerms::positiveId($invoice['id'] ?? null) !== $providerId
            || ($invoice['type'] ?? null) !== 'Invoice' || ! in_array($invoice['status'] ?? null, InvoiceXpressNoteTerms::Issued, true)
            || ($invoice['inverted_sequence_number'] ?? null) !== $original->number
            || ($invoice['date'] ?? null) !== $original->document_date->format('d/m/Y') || $original->document_date->gt(today())
            || InvoiceXpressIssueTerms::hundredths($invoice['before_taxes'] ?? null) !== $original->net_amount
            || InvoiceXpressIssueTerms::hundredths($invoice['taxes'] ?? null) !== $original->vat_amount
            || InvoiceXpressIssueTerms::hundredths($invoice['total'] ?? null) !== $original->total_amount
            || $clientId === null || $original->party_tax_id === null) {
            throw InvoiceXpressNoteTerms::invalid('originalId', 'A fatura original no InvoiceXpress diverge do registo no Contiu.');
        }

        return [
            'id' => $originalId,
            'providerId' => $providerId,
            'number' => $original->number,
            'customer' => InvoiceXpressIssueTerms::customer($client, $clientId, $original->party_tax_id),
            'lines' => InvoiceXpressNoteTerms::lines($invoice),
        ];
    }

    private function snapshot(array $input, string $subdomain, ?InvoiceXpressNoteAttempt $attempt = null): array
    {
        $options = InvoiceXpressIssue::options(forNewIssue: $attempt === null);
        if ($options === null || ! in_array($input['itemId'], $options['itemIds'], true)) {
            throw InvoiceXpressNoteTerms::invalid('itemId', 'Escolhe um artigo aprovado na configuração de emissão do InvoiceXpress.');
        }

        $original = $this->readOriginal($input['originalId'], $subdomain);
        $lineIndex = $input['line'] - 1;
        $line = $original['lines'][$lineIndex] ?? null;
        if ($line === null) {
            throw InvoiceXpressNoteTerms::invalid('line', 'Escolhe uma linha da fatura original.');
        }
        InvoiceXpressNoteTerms::originalLineIndex($original['lines'], $line);

        $customerId = $original['customer']['id'];
        $customer = InvoiceXpressIssueTerms::customer(
            $this->read("/clients/{$customerId}.json", 'client', $subdomain), $customerId, $original['customer']['nif'],
        );
        $itemId = $input['itemId'];
        $remoteItem = $this->read("/items/{$itemId}.json", 'item', $subdomain);
        $item = InvoiceXpressIssueTerms::item($remoteItem, $itemId);
        unset($item['id']);
        $tax = InvoiceXpressIssueTerms::tax($this->read('/taxes.json', 'taxes', $subdomain), $line['tax']['name'], 'PT', $line['tax']['basisPoints']);

        if ($item !== $line['article'] || $customer !== $original['customer']
            || $tax['id'] !== $line['tax']['id'] || InvoiceXpressIssueTerms::positiveId(data_get($remoteItem, 'tax.id')) !== $tax['id']
            || data_get($remoteItem, 'tax.name') !== $tax['name']
            || InvoiceXpressIssueTerms::hundredths(data_get($remoteItem, 'tax.value')) !== $tax['basisPoints']) {
            throw InvoiceXpressNoteTerms::invalid('itemId', 'O cliente, o artigo aprovado e o IVA têm de coincidir com a fatura original.');
        }

        $sequenceId = $options['sequenceId'];
        $sequence = InvoiceXpressNoteTerms::sequence($this->read("/sequences/{$sequenceId}.json", 'sequence', $subdomain), $sequenceId, $input['kind']);
        $history = $this->history($original, $subdomain, $attempt);
        $remaining = ['quantity' => $line['quantity'], 'total' => $line['totals']['total']];
        $available = $line['totals'];

        foreach ($history as $note) {
            if ($note['type'] !== InvoiceXpressNoteTerms::Types[$input['kind']]) {
                continue;
            }

            foreach ($note['lines'] as $adjustment) {
                if ($adjustment['index'] === $lineIndex) {
                    $remaining['quantity'] -= $adjustment['quantity'];
                    foreach ($available as $key => $amount) {
                        $available[$key] = $amount - $adjustment['totals'][$key];
                    }
                }
            }
        }

        $remaining['total'] = $available['total'];
        $totals = InvoiceXpressIssueTerms::totals($item['unitCents'], $input['quantity'], $tax['basisPoints']);
        if ($input['quantity'] > $remaining['quantity'] || $totals['total'] > $available['total']
            || $totals['net'] > $available['net'] || $totals['tax'] > $available['tax']) {
            throw InvoiceXpressNoteTerms::invalid('quantity', 'A quantidade ou o montante excede o saldo verificável desta linha para este tipo de nota.');
        }

        $date = today()->format('d/m/Y');

        return [
            'input' => $input,
            'original' => $original,
            'history' => $history,
            'remaining' => $remaining,
            'totals' => $totals,
            'sequence' => $sequence,
            'payload' => [
                'date' => $date,
                'due_date' => $date,
                'observations' => $input['reason'],
                'sequence_id' => (string) $sequence['id'],
                'currency_code' => 'EUR',
                'owner_invoice_id' => $original['providerId'],
                'client' => ['id' => $customerId],
                'items' => [[
                    'name' => $item['name'],
                    'description' => $item['description'],
                    'unit' => $item['unit'],
                    'unit_price' => sprintf('%d.%02d', intdiv($item['unitCents'], 100), $item['unitCents'] % 100),
                    'quantity' => $input['quantity'],
                    'tax' => ['name' => $tax['name']],
                ]],
            ],
        ];
    }

    private function history(array $original, string $subdomain, ?InvoiceXpressNoteAttempt $attempt): array
    {
        $history = [];

        foreach ($this->related($original['providerId'], $subdomain) as $note) {
            if (! in_array($note['type'], InvoiceXpressNoteTerms::Types, true) || $note['status'] === 'canceled') {
                continue;
            }

            $id = InvoiceXpressIssueTerms::positiveId($note['id'] ?? null);
            if ($id !== null && $attempt !== null && ($note['reference'] ?? null) === $this->reference($attempt)
                && $this->matches($attempt, $note, $id, related: true)) {
                continue;
            }

            if ($id === null || isset($history[$id]) || ! in_array($note['status'], InvoiceXpressNoteTerms::Issued, true)) {
                throw InvoiceXpressNoteTerms::invalid('confirmation', 'Existe uma nota associada sem estado ou saldo verificável no InvoiceXpress.');
            }

            $client = is_array($note['client'] ?? null) ? $note['client'] : [];
            if (InvoiceXpressIssueTerms::customer($client, $original['customer']['id'], $original['customer']['nif']) !== $original['customer']) {
                throw $this->invalidConfirmation();
            }

            $lines = array_map(fn (array $line): array => [
                'index' => InvoiceXpressNoteTerms::originalLineIndex($original['lines'], $line),
                'quantity' => $line['quantity'],
                'totals' => $line['totals'],
            ], InvoiceXpressNoteTerms::lines($note));

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
                throw InvoiceXpressNoteTerms::invalid('confirmation', 'Há uma nota no Contiu cujo saldo não foi confirmado no InvoiceXpress.');
            }
        }

        ksort($history);

        return $history;
    }

    private function matches(InvoiceXpressNoteAttempt $attempt, array $note, ?int $id = null, bool $related = false): bool
    {
        $snapshot = $attempt->snapshot;
        $original = $snapshot['original'];
        $kind = $snapshot['input']['kind'];
        $id ??= $attempt->remote_id;

        try {
            $rawOwner = $note['owner_invoice_id'] ?? null;
            $owner = InvoiceXpressIssueTerms::positiveId($rawOwner);
            if ($rawOwner === null && ! $related) {
                $related = collect($this->related($original['providerId'], $attempt->subdomain))->contains(
                    fn (array $row): bool => InvoiceXpressIssueTerms::positiveId($row['id'] ?? null) === $id
                        && $row['type'] === InvoiceXpressNoteTerms::Types[$kind],
                );
            }

            $lines = InvoiceXpressNoteTerms::lines($note);
            $client = is_array($note['client'] ?? null) ? $note['client'] : [];
            $customer = InvoiceXpressIssueTerms::customer($client, $original['customer']['id'], $original['customer']['nif']);
        } catch (ValidationException) {
            return false;
        }

        $expectedLine = $original['lines'][$snapshot['input']['line'] - 1];

        return ($owner === $original['providerId'] || ($rawOwner === null && $related))
            && ($attempt->remote_id === null || $id === $attempt->remote_id)
            && InvoiceXpressIssueTerms::positiveId($note['id'] ?? null) === $id
            && ($note['type'] ?? null) === InvoiceXpressNoteTerms::Types[$kind]
            && is_string($note['status'] ?? null)
            && InvoiceXpressIssueTerms::positiveId($note['sequence_id'] ?? null) === $snapshot['sequence']['id']
            && ($note['date'] ?? null) === $snapshot['payload']['date']
            && ($note['observations'] ?? null) === $snapshot['input']['reason']
            && ($note['reference'] ?? null) === $this->reference($attempt)
            && $customer === $original['customer']
            && $lines === [[...$expectedLine, 'quantity' => $snapshot['input']['quantity'], 'totals' => $snapshot['totals']]];
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
                $review = $this->import->reviewInvoice($note, $attempt->subdomain);
                if (! in_array($review['state'], ['new', 'same'], true)) {
                    throw $this->invalidConfirmation();
                }

                $document = $this->import->confirmInvoice($review['invoice'], expectedSubdomain: $attempt->subdomain);
                $expected = $review['after'];
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
            return $attempt->original_id !== null
                && $attempt->snapshot === $this->snapshot($attempt->snapshot['input'], $attempt->subdomain, $attempt);
        } catch (ValidationException) {
            return false;
        }
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
            throw new InvoiceXpressException('A lista de documentos relacionados não pôde ser verificada.');
        }

        return $documents;
    }

    private function read(string $path, string $key, string $subdomain): array
    {
        $data = $this->client->request('GET', $path, expectedSubdomain: $subdomain)[$key] ?? null;

        return is_array($data) ? $data : throw new InvoiceXpressException('O InvoiceXpress devolveu dados incompletos.');
    }

    private function findAttempt(int $id): InvoiceXpressNoteAttempt
    {
        $attempt = InvoiceXpressNoteAttempt::query()->lockForUpdate()->findOrFail($id);
        if ($this->client->configuration()->subdomain !== $attempt->subdomain) {
            throw InvoiceXpressNoteTerms::invalid('confirmation', 'Repõe a ligação original ao InvoiceXpress para verificar a tentativa.');
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

    private function requireCatalogueSafety(): void
    {
        if (! InvoiceXpressIssue::canWriteDocuments()) {
            throw InvoiceXpressNoteTerms::invalid('confirmation', 'A emissão aguarda a validação do catálogo do InvoiceXpress.');
        }
    }

    private function write(InvoiceXpressNoteAttempt $attempt, string $method, string $path, array $payload): array
    {
        $this->requireCatalogueSafety();

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
        return InvoiceXpressNoteTerms::invalid('confirmation', 'Os dados mudaram ou não puderam ser verificados. Revê a pré-visualização ou a tentativa registada.');
    }
}
