<?php

namespace App\Support;

use App\Enums\Language;
use App\Exceptions\AccountIsReadOnly;
use App\Exceptions\InvoiceXpressException;
use App\Models\Account;
use App\Models\FiscalDocument;
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
        $document = $latest?->fiscal_document_id === null ? null : FiscalDocument::query()->find($latest->fiscal_document_id);
        $before = $document === null ? null : $this->documentValues($document, $mapped['document']);
        $state = match (true) {
            $latest !== null && $document === null => 'conflict',
            $latest?->source_hash === $mapped['source_hash'] => 'same',
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
     * supplied to replace a previously imported version.
     */
    public function confirmInvoice(
        array $invoice,
        ?string $expectedCurrentHash = null,
        ?string $expectedSubdomain = null,
        ?string $expectedLocalHash = null,
        ?int $receivableId = null,
    ): FiscalDocument {
        $accountId = CurrentAccount::id();

        if (CurrentAccount::readOnly() || AccountantAccess::isReviewOnly()) {
            throw new AccountIsReadOnly;
        }

        $mapped = $this->map($invoice);

        return DB::transaction(function () use ($accountId, $mapped, $expectedCurrentHash, $expectedSubdomain, $expectedLocalHash, $receivableId): FiscalDocument {
            Account::query()->lockForUpdate()->findOrFail($accountId);
            $subdomain = $this->client->configuration()->subdomain;

            if ($expectedSubdomain !== null && $expectedSubdomain !== $subdomain) {
                throw $this->invalid(__('The InvoiceXpress account has changed. Look up the documents again.'));
            }

            $latest = $this->latest($subdomain, $mapped['provider_id']);
            $document = $latest?->fiscal_document_id === null
                ? null
                : FiscalDocument::query()->lockForUpdate()->find($latest->fiscal_document_id);

            if ($latest !== null && $document === null) {
                throw $this->invalid(__('The linked local document is missing.'));
            }

            if ($latest?->source_hash === $mapped['source_hash'] && $document !== null) {
                if ($receivableId !== null && (int) $document->links?->receivable_id !== $receivableId) {
                    throw $this->invalid(__('The document has already been imported. Review the link in the fiscal record.'));
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

            $document ??= new FiscalDocument;
            $document->fill($mapped['document'])->save();

            if ($receivableId !== null) {
                $document->links()->create(['receivable_id' => $receivableId]);
            }

            InvoiceXpressSnapshot::query()->create([
                'subdomain' => $subdomain,
                'provider_id' => $mapped['provider_id'],
                'source_hash' => $mapped['source_hash'],
                'source' => $mapped['source'],
                'fiscal_document_id' => $document->getKey(),
                'user_id' => auth()->id(),
            ]);

            return $document;
        });
    }

    /** @return Builder<Receivable> */
    private function matchingCharges(array $mapped): Builder
    {
        $document = $mapped['document'];
        $number = strtoupper(trim($document['party_tax_id'] ?? ''));
        $query = Receivable::query()->excludingCancelled()->with('billable');

        if (! in_array($document['type'], ['invoice', 'invoice_receipt'], true)
            || $document['status'] !== 'active' || $document['total_amount'] <= 0
            || ! preg_match('/\A[A-Z0-9]{5,32}\z/', $number)) {
            return $query->whereRaw('1 = 0');
        }

        $country = strtoupper($mapped['invoice']['client']['country'] ?? '');
        $portuguese = preg_match('/\A(?:PT)?([0-9]{9})\z/', $number, $match)
            && in_array($country, ['', 'PT', 'PORTUGAL'], true);

        if ($number === '999999990' || $number === 'PT999999990') {
            return $query->whereRaw('1 = 0');
        }

        $numbers = $portuguese ? [$match[1], 'PT'.$match[1]] : [$number];
        $issued = CarbonImmutable::parse($document['document_date']);
        $due = $mapped['invoice']['due_date'];
        $activeInvoiceLinks = FiscalDocumentLink::query()
            ->whereHas('fiscalDocument', fn (Builder $fiscal): Builder => $fiscal
                ->where('direction', 'issued')->where('status', 'active')->whereIn('type', ['invoice', 'invoice_receipt', 'debit_note']));

        return $query->where('amount', $document['total_amount'])
            ->whereHasMorph('billable', [Project::class, Retainer::class], fn (Builder $billable): Builder => $billable
                ->whereHas('client', fn (Builder $client): Builder => $client
                    ->whereIn(DB::raw('upper(vat_number)'), $numbers)
                    ->when($portuguese, fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query
                        ->where('fiscal_country_code', 'PT')->orWhereNull('fiscal_country_code')))))
            ->whereNotIn('id', (clone $activeInvoiceLinks)->whereNotNull('receivable_id')->select('receivable_id'))
            ->whereNotIn('id', PaymentAllocation::query()->whereIn('payment_id', $activeInvoiceLinks
                ->whereHas('fiscalDocument', fn (Builder $query): Builder => $query->where('type', 'invoice_receipt'))
                ->whereNotNull('payment_id')->select('payment_id'))->select('receivable_id'))
            ->when($due !== null,
                fn (Builder $charges): Builder => $charges->whereDate('due_on', $this->date($due)->format('Y-m-d')),
                fn (Builder $charges): Builder => $charges->whereDate('due_on', '>=', $issued->subDays(30))
                    ->whereDate('due_on', '<=', $issued->addDays(60)),
            )
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
        if (in_array($providerType, ['Invoice', 'InvoiceReceipt'], true)) {
            $basisPoints = InvoiceXpressIssueTerms::hundredths($invoice['retention'] ?? 0);
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

        if ($providerType === 'DebitNote' && ($net < 0 || $vat < 0 || $total <= 0 || $total !== $net + $vat)) {
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
            'total_amount' => $withholding['gross'] ?? $total,
            'party_name' => $partyName,
            'party_tax_id' => $partyTaxId,
        ];

        $source = [
            'provider_type' => $providerType,
            'document' => $document,
            ...($withholding === null ? [] : ['withholding' => $withholding]),
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
                'inverted_sequence_number' => $number,
                'date' => $date,
                'due_date' => $this->date($invoice['due_date'] ?? null)?->format('d/m/Y'),
                'currency' => 'EUR',
                'before_taxes' => (string) $invoice['before_taxes'],
                'taxes' => (string) $invoice['taxes'],
                'total' => (string) $invoice['total'],
                ...($withholding === null ? [] : ['retention' => (string) $invoice['retention']]),
                'client' => [
                    'name' => $partyName,
                    'fiscal_id' => $partyTaxId,
                    'country' => $this->text($invoice['client']['country'] ?? null, 80),
                ],
            ],
        ];
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
