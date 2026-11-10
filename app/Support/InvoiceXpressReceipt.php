<?php

namespace App\Support;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
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

    /** @return array{allocationId: int, paymentId: int, charge: string, invoice: string, paidOn: string, amount: int, series: string, revision: string} */
    public function preview(int $allocationId): array
    {
        abort_unless(InvoiceXpressIssueRepository::ownerCanIssue(), 403);
        $this->requireWriteAccess();
        $allocation = PaymentAllocation::query()->findOrFail($allocationId);
        if ($this->attemptFor($allocation)->exists()) {
            throw $this->invalid('Já existe uma tentativa para esta afetação. Consulta o histórico.');
        }

        $local = $this->local($allocation);
        $intent = $this->preflight($local);

        return [
            'allocationId' => $allocationId,
            'paymentId' => $local['paymentId'],
            'charge' => $local['charge'],
            'invoice' => $local['invoiceNumber'],
            'paidOn' => $local['paidOn'],
            'amount' => $local['amount'],
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
                if (! hash_equals($this->revision($intent), $revision)) {
                    throw $this->invalid('Os dados mudaram. Volta a pré-visualizar o recibo antes de confirmar.');
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
            throw $this->invalid('Já existe uma tentativa para esta afetação ou fatura. Consulta o histórico.');
        }

        return InvoiceXpressReceiptAttempt::query()->findOrFail($id);
    }

    public function recover(int $attemptId): InvoiceXpressReceiptAttempt
    {
        return $this->locked(function () use ($attemptId): InvoiceXpressReceiptAttempt {
            $attempt = InvoiceXpressReceiptAttempt::query()->lockForUpdate()->findOrFail($attemptId);

            if ($attempt->provider_receipt_id === null) {
                if ($this->client->configuration()->subdomain !== $attempt->subdomain) {
                    throw $this->invalid('A conta InvoiceXpress mudou. Restabelece a ligação à conta da tentativa para verificar o recibo.');
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
            'total_amount' => $local['amount'],
            'party_name' => $local['partyName'],
            'party_tax_id' => $local['partyTaxId'],
        ];
        $document = FiscalDocument::query()->create($data);
        $document->links()->create([
            'payment_id' => $local['paymentId'],
            'related_fiscal_document_id' => $local['invoiceId'],
        ]);
        $source = ['provider_type' => 'Receipt', 'document' => $data];
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
    }

    private function local(PaymentAllocation $allocation, bool $lock = false): array
    {
        $receivable = Receivable::query()->excludingCancelled()->when($lock, fn (Builder $query) => $query->lockForUpdate())->find($allocation->receivable_id);
        $payment = Payment::query()->when($lock, fn (Builder $query) => $query->lockForUpdate())->find($allocation->payment_id);
        if ($lock && $payment !== null && $receivable !== null) {
            $allocation = $payment->allocations()->where('receivable_id', $receivable->id)->lockForUpdate()->find($allocation->id)
                ?? throw $this->invalid('A afetação mudou. Volta a pré-visualizar o recibo.');
        }

        if ($payment === null || $receivable === null || $payment->isAdvance() || $payment->method !== PaymentMethod::Transfer
            || $allocation->amount <= 0 || $allocation->amount > $payment->amount || $allocation->amount > $receivable->amount
            || $payment->paid_on->isAfter(today()) || ! $allocation->allocated_on->isSameDay($payment->paid_on)
            || (int) $payment->allocations()->sum('amount') !== $payment->amount) {
            throw $this->invalid('A afetação deve pertencer a uma transferência registada, com data e montante válidos.');
        }

        $invoices = $this->issuedDocuments([FiscalDocumentType::Invoice, FiscalDocumentType::InvoiceReceipt])
            ->whereHas('links', fn (Builder $query) => $query->where('receivable_id', $receivable->id))
            ->when($lock, fn (Builder $query) => $query->lockForUpdate())->limit(2)->get();
        $invoice = $invoices->first();

        if ($invoices->count() !== 1 || $invoice->type !== FiscalDocumentType::Invoice || $invoice->currency !== 'EUR'
            || $invoice->total_amount !== $receivable->amount || $invoice->document_date->isAfter($payment->paid_on)) {
            throw $this->invalid('A cobrança precisa de uma única fatura ativa em EUR, emitida antes ou na data do recebimento.');
        }

        if (WithholdingRecord::query()->where(fn (Builder $query) => $query
            ->where('payment_allocation_id', $allocation->id)
            ->orWhere(fn (Builder $query) => $query->where('payment_id', $payment->id)->whereNull('payment_allocation_id'))
            ->orWhere('original_document_id', $invoice->id)->orWhere('fiscal_document_id', $invoice->id))->exists()) {
            throw $this->invalid('Os recibos com retenção na fonte estão bloqueados.');
        }

        if ($receivable->creditApplications()->effectiveAt(today()->toDateString())->exists()
            || $this->hasUntrackedLocalDocument($invoice->id, $receivable->id, $payment->id)) {
            throw $this->invalid('Já existem recibos ou regularizações locais por reconciliar para esta cobrança.');
        }

        $subdomain = $this->client->configuration()->subdomain;
        $snapshots = InvoiceXpressSnapshot::query()->where('fiscal_document_id', $invoice->id)
            ->where('subdomain', $subdomain)->latest('id')->get();
        $snapshot = $snapshots->first();
        $providerType = $snapshot?->source['provider_type'] ?? null;

        if ($invoice->origin !== 'InvoiceXpress' || $snapshot === null || $snapshot->provider_id < 1
            || $snapshots->contains(fn (InvoiceXpressSnapshot $other): bool => $other->provider_id !== $snapshot->provider_id)
            || ! in_array($providerType, ['Invoice', 'SimplifiedInvoice'], true)
            || InvoiceXpressSnapshot::query()->where('subdomain', $subdomain)->where('provider_id', $snapshot->provider_id)
                ->latest('id')->first()?->id !== $snapshot->id) {
            throw $this->invalid('A fatura original não está identificada de forma inequívoca na conta InvoiceXpress atual.');
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
        ];
    }

    private function preflight(array $local): array
    {
        $key = $local['providerType'] === 'Invoice' ? 'invoice' : 'simplified_invoice';
        $response = $this->client->request('GET', "/{$key}s/{$local['providerInvoiceId']}.json", expectedSubdomain: $local['subdomain']);
        $invoice = $response[$key] ?? null;

        if (! is_array($invoice) || ($invoice['id'] ?? null) !== $local['providerInvoiceId']
            || ($invoice['type'] ?? null) !== $local['providerType']
            || ! in_array($invoice['status'] ?? null, ['final', 'sent'], true) || ($invoice['archived'] ?? null) !== false
            || ! $this->isEuro($invoice) || $this->number($invoice) !== $local['invoiceNumber']
            || ($invoice['date'] ?? null) !== $local['invoiceDate']
            || $this->cents($invoice['total'] ?? null) !== $local['invoiceTotal']
            || $this->cents($invoice['before_taxes'] ?? null) !== $local['invoiceNet']
            || $this->cents($invoice['taxes'] ?? null) !== $local['invoiceVat']
            || ! is_int($clientId = $invoice['client']['id'] ?? null) || $clientId < 1) {
            throw $this->invalid('A fatura no InvoiceXpress não coincide com o registo local ou não está aberta para recebimentos.');
        }

        if (! $this->withoutRetention($invoice)) {
            throw $this->invalid('A emissão exige confirmação de que a fatura não tem retenção na fonte.');
        }

        $series = $this->receiptSeries($invoice, $key, $local['subdomain']);
        $known = InvoiceXpressReceiptAttempt::query()->where('subdomain', $local['subdomain'])
            ->where('provider_invoice_id', $local['providerInvoiceId'])->whereNotNull('provider_receipt_id')
            ->get()->keyBy('provider_receipt_id');
        $verified = [];
        $paid = 0;

        foreach ($this->related($local) as $document) {
            $id = $document['id'];
            if (($id === $local['providerInvoiceId'] && $document['type'] === $local['providerType']) || $document['status'] === 'canceled') {
                continue;
            }

            $prior = $known->get($id);
            $receipt = $prior === null ? null : $this->verifiedReceipt($document, $prior);
            if ($receipt === null || isset($verified[$id]) || $receipt['number'] !== $prior->receipt_number
                || $receipt['atcud'] !== $prior->receipt_atcud || ! $this->hasRecordedReceipt($prior)) {
                throw $this->invalid('Existem movimentos no InvoiceXpress por reconciliar antes de emitir outro recibo.');
            }

            $paid += $prior->intent['local']['amount'];
            $verified[$id] = $receipt['number'];
        }

        if (count($verified) !== $known->count() || $local['amount'] > $local['invoiceTotal'] - $paid) {
            throw $this->invalid('O saldo da fatura no InvoiceXpress não está confirmado para este montante.');
        }

        ksort($verified);

        return ['local' => $local, 'clientId' => $clientId, 'series' => $series, 'relatedReceipts' => $verified];
    }

    /** @return array{id: int, name: string, validationCode: string} */
    private function receiptSeries(array $invoice, string $invoiceKind, string $subdomain): array
    {
        $invoiceSequenceId = InvoiceXpressIssueTerms::positiveId($invoice['sequence_id'] ?? null);
        if ($invoiceSequenceId === null) {
            throw $this->invalid('A série da fatura original não está identificada no InvoiceXpress.');
        }

        $response = $this->client->request('GET', '/sequences.json', expectedSubdomain: $subdomain);
        $sequences = $response['sequences'] ?? null;
        if (! is_array($sequences) || ! array_is_list($sequences)) {
            throw $this->invalid('O InvoiceXpress não devolveu uma lista válida de séries.');
        }

        $matches = [];
        $names = [];
        foreach ($sequences as $sequence) {
            if (! is_array($sequence) || ! is_string($name = $sequence['serie'] ?? null)) {
                throw $this->invalid('O InvoiceXpress devolveu uma série inválida.');
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
            throw $this->invalid('A série da fatura original precisa de uma série de recibos registada na AT e identificada sem ambiguidades.');
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
            throw $this->invalid('O InvoiceXpress não devolveu uma lista válida de documentos relacionados.');
        }

        foreach ($documents as $document) {
            if (! is_array($document) || ! is_int($document['id'] ?? null) || $document['id'] < 1
                || ! is_string($document['type'] ?? null) || ! is_string($document['status'] ?? null)) {
                throw $this->invalid('O InvoiceXpress devolveu um documento relacionado inválido.');
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

        if ($id === $attempt->provider_invoice_id || ($attempt->reported_receipt_id !== null && $id !== $attempt->reported_receipt_id)
            || ($document['type'] ?? null) !== 'Receipt' || ! in_array($document['status'] ?? null, self::FINAL_RECEIPT_STATES, true)
            || ($document['observations'] ?? null) !== $attempt->note || ($document['date'] ?? null) !== $local['paidOn']
            || ! $this->isEuro($document) || ! $this->withoutRetention($document)
            || ($document['client']['id'] ?? null) !== $intent['clientId'] || $number === null
            || InvoiceXpressIssueTerms::positiveId($document['sequence_id'] ?? null) !== $series['id']
            || $serial === null || $atcud !== $series['validationCode'].'-'.$serial
            || $net === null || $vat === null || $net + $vat !== $local['amount']
            || $this->cents($document['total'] ?? null) !== $local['amount']) {
            return null;
        }

        return ['id' => $id, 'number' => $number, 'net' => $net, 'vat' => $vat, 'atcud' => $atcud];
    }

    private function hasRecordedReceipt(InvoiceXpressReceiptAttempt $attempt): bool
    {
        return $this->issuedDocuments([FiscalDocumentType::Receipt])->whereKey($attempt->fiscal_document_id)
            ->where('number', $attempt->receipt_number)->where('total_amount', $attempt->intent['local']['amount'])
            ->whereHas('links', fn (Builder $query) => $query->where('payment_id', $attempt->payment_id)
                ->where('related_fiscal_document_id', $attempt->intent['local']['invoiceId']))
            ->exists();
    }

    private function hasUntrackedLocalDocument(int $invoiceId, int $receivableId, int $paymentId): bool
    {
        return FiscalDocumentLink::query()
            ->where(function (Builder $query) use ($invoiceId, $receivableId, $paymentId): void {
                $query->where('related_fiscal_document_id', $invoiceId)->orWhere('receivable_id', $receivableId)
                    ->orWhere(fn (Builder $query) => $query->where('payment_id', $paymentId)->whereNull('related_fiscal_document_id'));
            })
            ->whereIn('fiscal_document_id', $this->issuedDocuments([
                FiscalDocumentType::Receipt, FiscalDocumentType::InvoiceReceipt, FiscalDocumentType::CreditNote,
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
            throw $this->invalid('A emissão de documentos está desativada para esta conta InvoiceXpress.');
        }
    }

    private function withoutRetention(array $document): bool
    {
        return array_key_exists('retention', $document)
            && ($document['retention'] === null || $this->cents($document['retention']) === 0);
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
        if ((! is_string($value) && ! is_int($value) && ! is_float($value))
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
