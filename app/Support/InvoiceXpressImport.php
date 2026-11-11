<?php

namespace App\Support;

use App\Enums\Language;
use App\Enums\PortugueseVatRegion;
use App\Exceptions\AccountIsReadOnly;
use App\Exceptions\InvoiceXpressException;
use App\Models\Account;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentDraft;
use App\Models\FiscalDocumentLink;
use App\Models\InvoiceXpressSnapshot;
use App\Models\PaymentAllocation;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\Retainer;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class InvoiceXpressImport
{
    public function __construct(private readonly InvoiceXpressClient $client) {}

    /**
     * @return array{
     *     state: 'new'|'same'|'changed'|'conflict', invoice: array, source_hash: string,
     *     current_hash: ?string, local_hash: ?string, before: ?array, after: array, suggestions: array<int, string>,
     *     withholding: ?array{basisPoints: int, gross: int, retained: int, cash: int}
     * }
     */
    public function reviewInvoice(array $invoice, ?string $expectedSubdomain = null): array
    {
        $subdomain = $this->client->configuration()->subdomain;

        if ($expectedSubdomain !== null && $subdomain !== $expectedSubdomain) {
            throw new InvoiceXpressException(__('The InvoiceXpress account has changed. Look up the documents again.'));
        }

        $mapped = $this->map($invoice);
        $latest = $this->latest($subdomain, $mapped['provider_id']);
        $mapped = $this->withPreviousTaxLines($mapped, $latest);
        $document = $latest?->fiscal_document_id === null ? null : FiscalDocument::query()->find($latest->fiscal_document_id);
        $before = $document === null ? null : $this->documentValues($document, $mapped['document']);
        $sameSource = $latest !== null && $this->sameSource($latest, $mapped);
        $state = match (true) {
            $latest !== null && $document === null => 'conflict',
            $latest !== null && $this->conflictingCancellation($latest, $mapped) => 'conflict',
            $sameSource && $this->pendingDraft($subdomain, $mapped['invoice']) !== null => $before === $mapped['document'] ? 'changed' : 'conflict',
            $sameSource => 'same',
            $this->numberExists($mapped['document'], $document?->id) => 'conflict',
            $latest !== null => 'changed',
            default => 'new',
        };

        return [
            'state' => $state,
            'invoice' => $mapped['invoice'],
            'source_hash' => $mapped['source_hash'],
            'current_hash' => $latest?->source_hash,
            'local_hash' => $before === null ? null : $this->hash($before),
            'before' => $before,
            'after' => $mapped['document'],
            'withholding' => $mapped['source']['withholding'] ?? null,
            'suggestions' => $state === 'new'
                ? $this->matchingCharges($mapped)->limit(8)->get()
                    ->mapWithKeys(fn (Receivable $charge): array => [
                        $charge->id => $charge->label.' · '.$charge->due_on->format(Language::resolve(app()->getLocale())->dateFormat()),
                    ])->all()
                : [],
        ];
    }

    /**
     * Registers the already reviewed official document locally. The current source hash must be
     * supplied to replace a previously imported version. Issuance callers can provide a
     * catalog tax after verifying the official line identities.
     */
    public function confirmInvoice(
        array $invoice,
        ?string $expectedCurrentHash = null,
        ?string $expectedSubdomain = null,
        ?string $expectedLocalHash = null,
        ?int $receivableId = null,
        array $verifiedLineTax = [],
    ): FiscalDocument {
        $accountId = CurrentAccount::id();

        if (CurrentAccount::readOnly() || AccountantAccess::isReviewOnly()) {
            throw new AccountIsReadOnly;
        }

        $mapped = $this->map($invoice);

        return DB::transaction(function () use ($accountId, $mapped, $verifiedLineTax, $expectedCurrentHash, $expectedSubdomain, $expectedLocalHash, $receivableId): FiscalDocument {
            Account::query()->lockForUpdate()->findOrFail($accountId);
            $subdomain = $this->client->configuration()->subdomain;

            if ($expectedSubdomain !== null && $expectedSubdomain !== $subdomain) {
                throw $this->invalid(__('The InvoiceXpress account has changed. Look up the documents again.'));
            }

            $latest = $this->latest($subdomain, $mapped['provider_id']);
            $mapped = $this->withPreviousTaxLines($mapped, $latest);
            $verified = $verifiedLineTax === [] ? $mapped : $this->map(self::withVerifiedLineTax($mapped['invoice'], $verifiedLineTax));
            $document = $latest?->fiscal_document_id === null
                ? null
                : FiscalDocument::query()->lockForUpdate()->find($latest->fiscal_document_id);

            if ($latest !== null && $document === null) {
                throw $this->invalid(__('The linked local document is missing.'));
            }

            $draft = $this->pendingDraft($subdomain, $mapped['invoice'], lock: true);
            if ($draft !== null) {
                if (($expectedCurrentHash !== null && $latest?->source_hash !== $expectedCurrentHash)
                    || ($expectedLocalHash !== null && ($document === null || $expectedLocalHash !== $this->hash($this->documentValues($document, $mapped['document']))))) {
                    throw $this->invalid(__('Look up the document again and confirm its current version.'));
                }

                if ($draft->remote_id === null) {
                    $draft->update(['remote_id' => $mapped['provider_id'], 'state' => FiscalDocumentDraft::Remote]);
                }

                return app(InvoiceXpressDraftSync::class)->refreshReviewed($draft, $mapped['source_hash'], $receivableId);
            }

            if ($latest !== null && $this->conflictingCancellation($latest, $mapped)) {
                throw $this->invalid(__('Look up the document again and confirm its current version.'));
            }

            if ($latest !== null && $document !== null && $this->sameSource($latest, $mapped)) {
                if (! $this->sameSource($latest, $verified)) {
                    throw $this->invalid(__('Look up the document again and confirm its current version.'));
                }

                if ($receivableId !== null && (int) $document->links?->receivable_id !== $receivableId) {
                    throw $this->invalid(__('The document has already been imported. Review the link in the fiscal record.'));
                }

                if ($latest->source_hash !== $verified['source_hash'] && $this->sameSource($latest, $verified, allowOmissions: false)) {
                    if ($this->documentValues($document, $mapped['document']) !== $mapped['document']) {
                        throw $this->invalid(__('Look up the document again and confirm its current version.'));
                    }

                    $this->store($document, $subdomain, $verified);
                }

                return $document;
            }

            if ($latest?->source_hash !== $expectedCurrentHash
                || ($expectedLocalHash !== null && ($document === null || $expectedLocalHash !== $this->hash($this->documentValues($document, $mapped['document']))))) {
                throw $this->invalid(__('Look up the document again and confirm its current version.'));
            }

            if ($this->numberExists($mapped['document'], $document?->id)) {
                throw $this->invalid(__('An issued document with this type and number already exists in Contiu.'));
            }

            if ($receivableId !== null && ($latest !== null
                || $this->matchingCharges($mapped)->lockForUpdate()->find($receivableId) === null)) {
                throw $this->invalid(__('The receivable no longer matches the invoice. Look up the documents again.'));
            }

            if ($document !== null && $this->changesChargeMatch($document, $latest->source['document'] ?? [], $mapped['document'])) {
                $linkedChargeId = $document->links()->lockForUpdate()->first()?->receivable_id;
                if ($linkedChargeId !== null && $this->chargesMatchingAmountAndIdentity($mapped)->lockForUpdate()->find($linkedChargeId) === null) {
                    throw $this->invalid(__('The receivable no longer matches the invoice. Look up the documents again.'));
                }
            }

            $document ??= new FiscalDocument;
            $this->store($document, $subdomain, $verified);

            if ($receivableId !== null) {
                $document->links()->create(['receivable_id' => $receivableId]);
            }

            return $document;
        });
    }

    /** Persists the provider read-back verified by InvoiceXpressDraftSync inside the account lock. */
    public function confirmDraft(
        FiscalDocumentDraft $draft,
        array $invoice,
        ?string $reviewedHash,
        ?int $receivableId,
        ?int $originalId,
    ): FiscalDocument {
        $mapped = $this->map($invoice);
        $subdomain = $draft->provider_account;
        $atcud = $invoice['atcud'];
        $latest = $this->latest($subdomain, $mapped['provider_id']);
        $reviewed = $this->withPreviousTaxLines($mapped, $latest);
        $preview = $draft->preview;
        $verifiedTax = $originalId !== null
            ? ($preview['original']['lines'][($preview['input']['line'] ?? 0) - 1]['tax'] ?? [])
            : (isset($preview['domesticVat']) ? ($preview['tax'] ?? []) : []);
        $mapped = $verifiedTax === [] ? $reviewed : $this->map(self::withVerifiedLineTax($reviewed['invoice'], $verifiedTax));
        $document = $latest?->fiscal_document_id === null ? null : FiscalDocument::query()->lockForUpdate()->find($latest->fiscal_document_id);
        $conflict = fn (): ValidationException => ValidationException::withMessages([
            'draft' => __('The fiscal document or its link needs review in Contiu.'),
        ]);

        if ((int) $draft->account_id !== CurrentAccount::id() || $draft->remote_id !== $mapped['provider_id']
            || ($reviewedHash !== null && ! in_array($reviewedHash, [
                $reviewed['source_hash'], $mapped['source_hash'], $this->hash($this->sourceWithoutTaxLines($reviewed['source'])),
            ], true))
            || ($draft->fiscal_document_id !== null && ($draft->fiscal_document_id !== $document?->id || $document?->atcud !== $atcud))
            || ($document?->atcud !== null && $document->atcud !== $atcud)
            || ($latest !== null && ($document === null
                || ! $this->sameSource($latest, $reviewed)
                || ! $this->sameSource($latest, $mapped)
                || $this->documentValues($document, $mapped['document']) !== $mapped['document']))
            || $this->numberExists($mapped['document'], $document?->id)) {
            throw $conflict();
        }

        $link = $document?->links()->lockForUpdate()->first();
        $existingCharge = $link?->receivable_id === null ? null : (int) $link->receivable_id;
        if ($link?->payment_id !== null || $link?->expense_id !== null
            || ($link?->related_fiscal_document_id !== null && (int) $link->related_fiscal_document_id !== $originalId)
            || ($existingCharge !== null && ($originalId !== null || ($receivableId !== null && $existingCharge !== $receivableId)))) {
            throw $conflict();
        }

        $receivableId ??= $existingCharge;
        if ($receivableId !== null && $this->matchingCharges($mapped, $document?->id, checkDueDate: $draft->document_type !== 'invoice_receipt', allowVoided: true)
            ->lockForUpdate()->find($receivableId) === null) {
            throw $conflict();
        }

        if ($document === null) {
            $document = new FiscalDocument(['atcud' => $atcud]);
            $this->store($document, $subdomain, $mapped);
        } else {
            if ($document->atcud === null) {
                $document->update(['atcud' => $atcud]);
            }

            if ($latest->source_hash !== $mapped['source_hash'] && $this->sameSource($latest, $mapped, allowOmissions: false)) {
                $this->store($document, $subdomain, $mapped);
            }
        }

        if (($receivableId !== null && $existingCharge === null) || ($originalId !== null && $link?->related_fiscal_document_id === null)) {
            $document->links()->updateOrCreate([], ['receivable_id' => $receivableId, 'related_fiscal_document_id' => $originalId]);
        }

        if ($draft->fiscal_document_id !== $document->id || $draft->state !== FiscalDocumentDraft::Promoted) {
            $draft->update(['fiscal_document_id' => $document->id, 'state' => FiscalDocumentDraft::Promoted]);
        }

        return $document;
    }

    private function store(FiscalDocument $document, string $subdomain, array $mapped): void
    {
        $document->fill($mapped['document'])->save();

        InvoiceXpressSnapshot::query()->create([
            'subdomain' => $subdomain,
            'provider_id' => $mapped['provider_id'],
            'source_hash' => $mapped['source_hash'],
            'source' => $mapped['source'],
            'fiscal_document_id' => $document->getKey(),
            'user_id' => auth()->id(),
        ]);
    }

    private function pendingDraft(string $subdomain, array $invoice, bool $lock = false): ?FiscalDocumentDraft
    {
        $query = FiscalDocumentDraft::query()
            ->where('provider', 'invoicexpress')->where('provider_account', $subdomain)
            ->when($lock, fn (Builder $query) => $query->lockForUpdate());
        $bound = (clone $query)->where('remote_id', $invoice['id'])->first();
        if ($bound !== null) {
            return $bound->fiscal_document_id === null
                && in_array($bound->state, [FiscalDocumentDraft::Remote, FiscalDocumentDraft::Blocked], true) ? $bound : null;
        }

        $prefix = 'Contiu draft ';
        $reference = $invoice['reference'] ?? '';
        $sendKey = str_starts_with($reference, $prefix) ? substr($reference, strlen($prefix)) : '';
        if (! Str::isUuid($sendKey)) {
            return null;
        }

        return $query->where('send_key', $sendKey)->where('state', FiscalDocumentDraft::Sending)
            ->whereNull('remote_id')->whereNull('fiscal_document_id')->first();
    }

    private function changesChargeMatch(FiscalDocument $document, array $previous, array $incoming): bool
    {
        $incomingMatch = array_intersect_key($incoming, array_flip(['type', 'total_amount', 'party_tax_id']));
        $localMatch = $this->documentValues($document, $incomingMatch);
        $previousMatch = array_replace($localMatch, array_intersect_key($previous, $incomingMatch));

        return in_array($previousMatch['type'], ['invoice', 'invoice_receipt'], true)
            && ($incomingMatch !== $localMatch || $incomingMatch !== $previousMatch);
    }

    /** @return Builder<Receivable> */
    private function chargesMatchingAmountAndIdentity(array $mapped): Builder
    {
        $document = $mapped['document'];
        $number = strtoupper(trim($document['party_tax_id'] ?? ''));
        $query = Receivable::query();

        if (! in_array($document['type'], ['invoice', 'invoice_receipt'], true)
            || $document['total_amount'] <= 0 || ! preg_match('/\A[A-Z0-9]{5,32}\z/', $number)
            || in_array($number, ['999999990', 'PT999999990'], true)) {
            return $query->whereRaw('1 = 0');
        }

        $country = strtoupper($mapped['invoice']['client']['country'] ?? '');
        $portuguese = preg_match('/\A(?:PT)?([0-9]{9})\z/', $number, $match)
            && in_array($country, ['', 'PT', 'PORTUGAL'], true);

        $numbers = $portuguese ? [$match[1], 'PT'.$match[1]] : [$number];

        return $query->where('amount', $document['total_amount'])
            ->whereHasMorph('billable', [Project::class, Retainer::class], fn (Builder $billable): Builder => $billable
                ->whereHas('client', fn (Builder $client): Builder => $client
                    ->whereIn(DB::raw('upper(vat_number)'), $numbers)
                    ->when($portuguese, fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query
                        ->where('fiscal_country_code', 'PT')->orWhereNull('fiscal_country_code')))));
    }

    /** @return Builder<Receivable> */
    private function matchingCharges(array $mapped, ?int $exceptDocumentId = null, bool $checkDueDate = true, bool $allowVoided = false): Builder
    {
        $document = $mapped['document'];
        $query = $this->chargesMatchingAmountAndIdentity($mapped)->excludingCancelled()->with('billable');
        if ($document['status'] !== 'active' && ! ($allowVoided && $document['status'] === 'voided')) {
            return $query->whereRaw('1 = 0');
        }

        $issued = CarbonImmutable::parse($document['document_date']);
        $due = $mapped['invoice']['due_date'];
        $activeInvoiceLinks = FiscalDocumentLink::query()
            ->when($exceptDocumentId !== null, fn (Builder $links): Builder => $links->where('fiscal_document_id', '!=', $exceptDocumentId))
            ->whereHas('fiscalDocument', fn (Builder $fiscal): Builder => $fiscal
                ->where('direction', 'issued')->where('status', 'active')->whereIn('type', ['invoice', 'invoice_receipt', 'debit_note']));

        return $query->whereNotIn('id', (clone $activeInvoiceLinks)->whereNotNull('receivable_id')->select('receivable_id'))
            ->whereNotIn('id', PaymentAllocation::query()->whereIn('payment_id', $activeInvoiceLinks
                ->whereHas('fiscalDocument', fn (Builder $query): Builder => $query->where('type', 'invoice_receipt'))
                ->whereNotNull('payment_id')->select('payment_id'))->select('receivable_id'))
            ->when($checkDueDate, fn (Builder $charges): Builder => $due !== null
                ? $charges->whereDate('due_on', $this->date($due)->format('Y-m-d'))
                : $charges->whereDate('due_on', '>=', $issued->subDays(30))->whereDate('due_on', '<=', $issued->addDays(60)))
            ->orderBy('due_on')->orderBy('id');
    }

    /** @return array{provider_id: int, source_hash: string, source: array, document: array, invoice: array} */
    private function map(array $response): array
    {
        $invoice = $response['invoice'] ?? $response['invoice_receipt'] ?? $response['simplified_invoice']
            ?? $response['credit_note'] ?? $response['debit_note'] ?? $response;

        if (! is_array($invoice)) {
            throw $this->invalid(__('The InvoiceXpress document is invalid.'));
        }

        $providerId = $invoice['id'] ?? null;
        $providerType = $invoice['type'] ?? null;
        $status = $invoice['status'] ?? null;
        $type = match ($providerType) {
            'Invoice', 'SimplifiedInvoice' => 'invoice',
            'InvoiceReceipt' => 'invoice_receipt',
            'CreditNote' => 'credit_note',
            'DebitNote' => 'debit_note',
            default => null,
        };

        if (! is_int($providerId) || $providerId < 1 || $type === null
            || ! in_array($status, ['final', 'sent', 'settled', 'canceled'], true)) {
            throw $this->invalid(__('The InvoiceXpress document type or status is unsupported.'));
        }

        if (! in_array($invoice['currency'] ?? null, ['Euro', 'EUR'], true)) {
            throw $this->invalid(__('Documents in euros can be imported.'));
        }

        $date = $invoice['date'] ?? null;
        $number = $this->text($invoice['inverted_sequence_number'] ?? null, 255)
            ?? $this->text($invoice['sequence_number'] ?? null, 255);

        if (! is_string($date) || ! preg_match('/\A[0-9]{2}\/[0-9]{2}\/[1-9][0-9]{3}\z/', $date) || $number === null) {
            throw $this->invalid(__('The InvoiceXpress document has an invalid number or date.'));
        }

        $issuedOn = $this->date($date);

        if ($issuedOn === null) {
            throw $this->invalid(__('The InvoiceXpress document has an invalid date.'));
        }

        $net = $this->cents($invoice['before_taxes'] ?? null);
        $vat = $this->cents($invoice['taxes'] ?? null);
        $total = $this->cents($invoice['total'] ?? null);
        $withholding = null;
        $basisPoints = InvoiceXpressIssueTerms::hundredths($invoice['retention'] ?? 0);
        $adjustment = in_array($providerType, ['CreditNote', 'DebitNote'], true);
        if (in_array($providerType, ['Invoice', 'InvoiceReceipt'], true) || ($adjustment && $basisPoints !== 0)) {
            if ($basisPoints === null || $basisPoints > 10000 || $net < 0 || $vat < 0) {
                throw $this->invalid(__('The InvoiceXpress document contains invalid withholding details or tax amounts.'));
            }

            $gross = $net + $vat;
            $amounts = InvoiceXpressIssueTerms::withholdingAmounts($basisPoints, $net, $gross);
            if ($amounts['cash'] !== $total) {
                throw $this->invalid(__('The InvoiceXpress total does not match the net amount plus VAT minus withholding.'));
            }

            $withholding = $basisPoints === 0 ? null : $amounts;
        }

        $gross = $withholding['gross'] ?? $total;
        if ($providerType === 'DebitNote' && ($net < 0 || $vat < 0 || $gross <= 0 || $gross !== $net + $vat)) {
            throw $this->invalid(__('The debit note total must be positive and match the sum of the net amount and VAT.'));
        }

        $partyName = $this->text($invoice['client']['name'] ?? null, 255);
        $partyTaxId = $this->text($invoice['client']['fiscal_id'] ?? null, 64);

        $document = [
            'type' => $type,
            'direction' => 'issued',
            'status' => $status === 'canceled' ? 'voided' : 'active',
            'number' => $number,
            'origin' => 'InvoiceXpress',
            'document_date' => $issuedOn->format('Y-m-d'),
            'currency' => 'EUR',
            'net_amount' => $net,
            'vat_amount' => $vat,
            'total_amount' => $gross,
            'party_name' => $partyName,
            'party_tax_id' => $partyTaxId,
        ];

        $taxLines = $this->taxLines($invoice['items'] ?? null);
        $source = [
            'provider_type' => $providerType,
            'document' => $document,
            ...($withholding === null ? [] : ['withholding' => $withholding]),
            ...($taxLines === [] ? [] : ['tax_lines' => $taxLines]),
        ];

        return [
            'provider_id' => $providerId,
            'source_hash' => $this->hash($source),
            'source' => $source,
            'document' => $document,
            'invoice' => [
                'id' => $providerId,
                'type' => $providerType,
                'status' => $status,
                'reference' => is_string($invoice['reference'] ?? null) && strlen($invoice['reference']) <= 255 ? $invoice['reference'] : null,
                'inverted_sequence_number' => $number,
                'date' => $date,
                'due_date' => $this->date($invoice['due_date'] ?? null)?->format('d/m/Y'),
                'currency' => 'EUR',
                'before_taxes' => (string) $invoice['before_taxes'],
                'taxes' => (string) $invoice['taxes'],
                'total' => (string) $invoice['total'],
                ...($withholding === null ? [] : ['retention' => (string) $invoice['retention']]),
                ...($taxLines === [] ? [] : ['items' => $taxLines]),
                'client' => [
                    'name' => $partyName,
                    'fiscal_id' => $partyTaxId,
                    'country' => $this->text($invoice['client']['country'] ?? null, 80),
                ],
            ],
        ];
    }

    /**
     * Use the catalog tax only after the issue flow has verified the official lines.
     * Each line must identify that same tax before the catalog can supply omitted details.
     */
    public static function withVerifiedLineTax(array $invoice, array $tax): array
    {
        if (! is_string($tax['region'] ?? null) || PortugueseVatRegion::tryFrom($tax['region']) === null
            || ($tax['basisPoints'] ?? 0) <= 0 || ! is_array($invoice['items'] ?? null)) {
            return $invoice;
        }

        foreach ($invoice['items'] as $index => $line) {
            $remote = $line['tax'] ?? null;
            if (! is_array($remote) || ! self::sameLineTax($remote, $tax)) {
                continue;
            }

            foreach (['region', 'code'] as $field) {
                if (! isset($tax[$field])) {
                    continue;
                }

                if (isset($remote[$field]) && $remote[$field] !== '' && $remote[$field] !== $tax[$field]) {
                    throw ValidationException::withMessages(['tax' => __('The configured VAT name must identify a single tax with the expected region and rate.')]);
                }

                $invoice['items'][$index]['tax'][$field] = $tax[$field];
            }
        }

        return $invoice;
    }

    /** Compares the provider line identity with an already verified tax. */
    public static function sameLineTax(array $lineTax, array $verifiedTax): bool
    {
        return ($verifiedTax['id'] ?? null) !== null
            && InvoiceXpressIssueTerms::positiveId($lineTax['id'] ?? null) === $verifiedTax['id']
            && is_string($verifiedTax['name'] ?? null)
            && ($lineTax['name'] ?? null) === $verifiedTax['name']
            && isset($verifiedTax['basisPoints'])
            && InvoiceXpressIssueTerms::hundredths($lineTax['value'] ?? null) === $verifiedTax['basisPoints'];
    }

    private function taxLines(mixed $items): array
    {
        if (! is_array($items) || ! array_is_list($items)) {
            return [];
        }

        $lines = [];
        foreach ($items as $item) {
            $line = [];
            $tax = $item['tax'] ?? null;
            if (is_array($tax)) {
                $id = InvoiceXpressIssueTerms::positiveId($tax['id'] ?? null);
                $name = $tax['name'] ?? null;
                $rate = $this->lineDecimal($tax['value'] ?? null);
                $region = $tax['region'] ?? null;
                $code = $tax['code'] ?? null;
                $details = [
                    ...($id === null ? [] : ['id' => $id]),
                    ...(is_string($name) && mb_check_encoding($name) && trim($name) !== '' && mb_strlen($name) <= 255 ? ['name' => $name] : []),
                    ...($rate === null ? [] : ['value' => $rate]),
                    ...(is_string($region) && preg_match('/\A[A-Z]{2}(?:-[A-Z0-9]{1,8})?\z/', $region) ? ['region' => $region] : []),
                    ...(is_string($code) && preg_match('/\A[A-Z0-9]{1,20}\z/', $code) ? ['code' => $code] : []),
                ];
                if ($details !== []) {
                    $line['tax'] = $details;
                }
            }

            foreach (['subtotal', 'tax_amount', 'total'] as $field) {
                $amount = $this->lineDecimal($item[$field] ?? null);
                if ($amount !== null) {
                    $line[$field] = $amount;
                }
            }

            $lines[] = $line;
        }

        return array_filter($lines) === [] ? [] : $lines;
    }

    private function lineDecimal(mixed $value): ?string
    {
        if ((! is_string($value) && ! is_int($value) && ! is_float($value))
            || ! preg_match('/\A(-?)([0-9]{1,12})(?:[.,]([0-9]{1,24}))?\z/', (string) $value, $parts)) {
            return null;
        }

        $fraction = rtrim($parts[3] ?? '', '0');

        return $parts[1].(int) $parts[2].($fraction === '' ? '' : '.'.$fraction);
    }

    private function withPreviousTaxLines(array $mapped, ?InvoiceXpressSnapshot $latest): array
    {
        $previous = $latest?->source['tax_lines'] ?? [];
        if ($previous === []) {
            return $mapped;
        }

        $lines = $mapped['source']['tax_lines'] ?? [];
        $sameDocument = $this->sourceWithoutTaxLines($latest->source, ignoreStatus: true)
            === $this->sourceWithoutTaxLines($mapped['source'], ignoreStatus: true);
        if ($lines === []) {
            if (! $sameDocument) {
                return $mapped;
            }

            $lines = $previous;
        } elseif (count($lines) === count($previous)) {
            $amountCounts = $sameDocument ? array_count_values(array_map($this->lineAmountKey(...), $previous)) : [];
            foreach ($lines as $index => $line) {
                $tax = $line['tax'] ?? [];
                $prior = $previous[$index];
                $priorTax = $prior['tax'] ?? [];
                $sameTaxIdentity = (isset($tax['id']) && $tax['id'] === ($priorTax['id'] ?? null))
                    || (isset($tax['name'], $tax['value']) && $tax['name'] === ($priorTax['name'] ?? null)
                        && $tax['value'] === ($priorTax['value'] ?? null));

                $amountKey = $this->lineAmountKey($line);
                $sameLine = $sameDocument && (count($lines) === 1
                    || ($amountKey !== '' && ($amountCounts[$amountKey] ?? 0) === 1 && $amountKey === $this->lineAmountKey($prior)));
                if (($sameTaxIdentity || $sameLine) && $this->taxLineMatches($prior, $line, allowOmissions: true)) {
                    $lines[$index] = $sameDocument
                        ? array_replace_recursive($prior, $line)
                        : [...$line, 'tax' => array_replace($priorTax, $tax)];
                }
            }
        }

        $mapped['invoice']['items'] = $mapped['source']['tax_lines'] = $this->taxLines($lines);
        $mapped['source_hash'] = $this->hash($mapped['source']);

        return $mapped;
    }

    private function lineAmountKey(array $line): string
    {
        return isset($line['subtotal'], $line['tax_amount'], $line['total'])
            ? implode('|', [$line['subtotal'], $line['tax_amount'], $line['total']])
            : '';
    }

    /** A new snapshot can add compatible evidence, while the original hash still identifies the stored revision. */
    private function sameSource(InvoiceXpressSnapshot $latest, array $mapped, bool $allowOmissions = true): bool
    {
        return $latest->source_hash === $mapped['source_hash']
            || ($this->sourceWithoutTaxLines($latest->source) === $this->sourceWithoutTaxLines($mapped['source'])
                && $this->taxLinesMatch($latest->source, $mapped['source'], $allowOmissions));
    }

    private function conflictingCancellation(InvoiceXpressSnapshot $latest, array $mapped): bool
    {
        return $mapped['document']['status'] === 'voided'
            && ($latest->source['document']['status'] ?? null) === 'active'
            && $this->sourceWithoutTaxLines($latest->source, ignoreStatus: true) === $this->sourceWithoutTaxLines($mapped['source'], ignoreStatus: true)
            && ! $this->taxLinesMatch($latest->source, $mapped['source'], allowOmissions: true);
    }

    private function sourceWithoutTaxLines(array $source, bool $ignoreStatus = false): array
    {
        unset($source['tax_lines']);
        if ($ignoreStatus) {
            unset($source['document']['status']);
        }

        return $source;
    }

    private function taxLinesMatch(array $previous, array $incoming, bool $allowOmissions): bool
    {
        $priorLines = $previous['tax_lines'] ?? [];
        $lines = $incoming['tax_lines'] ?? [];
        if ($priorLines === [] || ($allowOmissions && $lines === [])) {
            return true;
        }

        if (count($priorLines) !== count($lines)) {
            return false;
        }

        foreach ($priorLines as $index => $prior) {
            if (! $this->taxLineMatches($prior, $lines[$index], $allowOmissions)) {
                return false;
            }
        }

        return true;
    }

    private function taxLineMatches(array $prior, array $line, bool $allowOmissions): bool
    {
        $priorTax = $prior['tax'] ?? [];
        $tax = $line['tax'] ?? [];
        unset($prior['tax'], $line['tax']);

        return array_diff_assoc($allowOmissions ? array_intersect_key($prior, $line) : $prior, $line) === []
            && array_diff_assoc($allowOmissions ? array_intersect_key($priorTax, $tax) : $priorTax, $tax) === [];
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || ! preg_match('/\A[0-9]{2}\/[0-9]{2}\/[1-9][0-9]{3}\z/', $value)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!d/m/Y', $value);

        return $date !== false && $date->format('d/m/Y') === $value ? $date : null;
    }

    private function latest(string $subdomain, int $providerId): ?InvoiceXpressSnapshot
    {
        return InvoiceXpressSnapshot::query()->where('subdomain', $subdomain)->where('provider_id', $providerId)->latest('id')->first();
    }

    private function numberExists(array $document, ?int $except): bool
    {
        return FiscalDocument::query()
            ->where('direction', $document['direction'])
            ->where('type', $document['type'])
            ->where('number', $document['number'])
            ->when($except !== null, fn (Builder $query) => $query->whereKeyNot($except))
            ->exists();
    }

    private function documentValues(FiscalDocument $document, array $fields): array
    {
        $values = $document->attributesToArray();
        $values['document_date'] = $document->document_date->toDateString();

        return array_replace(array_fill_keys(array_keys($fields), null), array_intersect_key($values, $fields));
    }

    private function text(mixed $value, int $limit): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || ! mb_check_encoding($value) || mb_strlen($value) > $limit || str_contains($value, "\0")) {
            throw $this->invalid(__('The InvoiceXpress document contains invalid text.'));
        }

        return trim($value) === '' ? null : trim($value);
    }

    private function cents(mixed $value): int
    {
        if (is_float($value)) {
            $value = json_encode($value);
        }

        if ((! is_int($value) && ! is_string($value))
            || ! preg_match('/\A(-?)([0-9]{1,12})(?:\.([0-9]{1,2}))?\z/', (string) $value, $parts)) {
            throw $this->invalid(__('The InvoiceXpress document contains an invalid amount.'));
        }

        $amount = (int) $parts[2] * 100 + (int) str_pad($parts[3] ?? '', 2, '0');

        return $parts[1] === '-' ? -$amount : $amount;
    }

    private function hash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function invalid(string $message): ValidationException
    {
        return ValidationException::withMessages(['invoice' => $message]);
    }
}
