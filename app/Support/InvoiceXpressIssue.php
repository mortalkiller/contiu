<?php

namespace App\Support;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Exceptions\InvoiceXpressException;
use App\Models\Account;
use App\Models\FiscalDocumentLink;
use App\Models\FiscalProfile;
use App\Models\InvoiceXpressConfig;
use App\Models\InvoiceXpressIssueAttempt;
use App\Models\InvoiceXpressSnapshot;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\Retainer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InvoiceXpressIssue
{
    public const string SettingsKey = InvoiceXpressIssueSettings::Key;

    public const int MaxLines = 10;

    public const array DocumentTypes = [
        'invoice' => 'Fatura',
        'invoice_receipt' => 'Fatura-recibo',
        'simplified_invoice' => 'Fatura simplificada',
    ];

    public function __construct(
        private readonly InvoiceXpressClient $client,
        private readonly InvoiceXpressIssueRepository $attempts,
        private readonly InvoiceXpressImport $documents,
    ) {}

    /** @return array{itemIds: list<int>, taxName: string, sequenceId: int}|null */
    public static function options(bool $forNewIssue = true): ?array
    {
        if (! InvoiceXpressIssueRepository::ownerCanIssue()) {
            return null;
        }

        $config = InvoiceXpressConfig::current();
        $settings = InvoiceXpressIssueSettings::stored();
        $ids = $settings['item_ids'] ?? null;
        $tax = $settings['tax_name'] ?? null;
        $sequenceId = $settings['sequence_id'] ?? null;

        if ($config === null || ($settings['subdomain'] ?? null) !== $config->subdomain
            || ($forNewIssue && ($settings['user_id'] ?? null) !== (int) auth()->id())
            || ($settings['provider'] ?? null) !== 'invoicexpress' || ! is_array($ids) || ! array_is_list($ids) || $ids === []
            || ! is_string($tax) || $tax === '' || mb_strlen($tax) > 255
            || ! is_int($sequenceId) || $sequenceId < 1
            || array_filter($ids, fn (mixed $id): bool => ! is_int($id) || $id < 1) !== []) {
            return null;
        }

        return ['itemIds' => array_values(array_unique($ids)), 'taxName' => $tax, 'sequenceId' => $sequenceId];
    }

    public static function canWriteDocuments(): bool
    {
        $subdomain = InvoiceXpressConfig::current()?->subdomain;

        return $subdomain !== null && InvoiceXpressClient::catalogValidatedFor($subdomain);
    }

    private function requireCatalogValidation(): void
    {
        if (! self::canWriteDocuments()) {
            throw ValidationException::withMessages(['confirmation' => 'A emissão aguarda a validação de que a API preserva o catálogo do InvoiceXpress.']);
        }
    }

    public function preview(
        int $receivableId,
        int $customerId,
        int $itemId,
        int $quantity,
        array $additionalLines = [],
        string $documentType = 'invoice',
        ?string $exemptionReason = null,
    ): array {
        abort_unless(self::options() !== null, 403);

        return DB::transaction(function () use ($receivableId, $customerId, $itemId, $quantity, $additionalLines, $documentType, $exemptionReason): array {
            Account::query()->lockForUpdate()->findOrFail(CurrentAccount::id());
            $snapshot = $this->snapshot($receivableId, $customerId, $itemId, $quantity, $additionalLines, $documentType, $exemptionReason, allowAdvancePreview: true);

            return isset($snapshot['advanceSettlement']) ? $snapshot : [...$snapshot, 'revision' => $this->revision($snapshot)];
        });
    }

    public function confirm(
        int $receivableId,
        int $customerId,
        int $itemId,
        int $quantity,
        string $revision,
        array $additionalLines = [],
        string $documentType = 'invoice',
        ?string $exemptionReason = null,
    ): InvoiceXpressIssueAttempt {
        abort_unless(self::options() !== null, 403);

        $attempt = DB::transaction(function () use ($receivableId, $customerId, $itemId, $quantity, $revision, $additionalLines, $documentType, $exemptionReason): InvoiceXpressIssueAttempt {
            Account::query()->lockForUpdate()->findOrFail(CurrentAccount::id());
            $this->requireCatalogValidation();
            $snapshot = $this->snapshot($receivableId, $customerId, $itemId, $quantity, $additionalLines, $documentType, $exemptionReason);

            if (! hash_equals($this->revision($snapshot), $revision)) {
                throw ValidationException::withMessages(['confirmation' => 'Os dados mudaram. Volta a pré-visualizar o documento antes de confirmar.']);
            }

            return $this->attempts->reserve("receivable:{$receivableId}", $this->target(), $snapshot, (int) auth()->id());
        });

        return $this->recover($attempt->id);
    }

    public function recover(int $attemptId): InvoiceXpressIssueAttempt
    {
        abort_unless(InvoiceXpressIssueRepository::ownerCanIssue(), 403);

        $attempt = $this->attempts->locked($attemptId, function (InvoiceXpressIssueAttempt $attempt): InvoiceXpressIssueAttempt {
            $this->assertTarget($attempt);

            if ($attempt->state !== InvoiceXpressIssueAttempt::Pending) {
                return $attempt;
            }

            $this->requireCatalogValidation();

            try {
                if (! $this->stillCurrent($attempt)) {
                    return $this->block($attempt);
                }

                $resource = $this->resource($attempt);
                $key = $attempt->snapshot['documentType'] ?? 'invoice';
                $response = $this->client->request('POST', "/{$resource}.json", payload: [
                    'proprietary_uid' => $attempt->proprietary_uid,
                    $key => $attempt->snapshot['invoice'],
                ]);
            } catch (InvoiceXpressException) {
                return $attempt;
            }

            $id = InvoiceXpressIssueTerms::positiveId(data_get($response, "{$key}.id"));

            if ($id === null) {
                return $attempt;
            }

            if (InvoiceXpressIssueAttempt::where('provider_target', $attempt->provider_target)->where('remote_draft_id', $id)->exists()) {
                return $this->block($attempt);
            }

            $attempt->forceFill(['remote_draft_id' => $id, 'state' => InvoiceXpressIssueAttempt::Draft])->save();

            return $attempt;
        });

        if ($attempt->state !== InvoiceXpressIssueAttempt::Draft) {
            return $attempt;
        }

        return $this->attempts->locked($attemptId, function (InvoiceXpressIssueAttempt $attempt): InvoiceXpressIssueAttempt {
            $this->assertTarget($attempt);

            if ($attempt->state !== InvoiceXpressIssueAttempt::Draft) {
                return $attempt;
            }

            try {
                $document = $this->getDocument($attempt);

                if (! $this->matches($attempt, $document)) {
                    return $this->block($attempt);
                }

                if ($document['status'] === $this->finalStatus($attempt)) {
                    return $this->saveFinal($attempt, $document);
                }

                if ($document['status'] !== 'draft' || ! $this->stillCurrent($attempt)) {
                    return $this->block($attempt);
                }

                $this->requireCatalogValidation();

                $resource = $this->resource($attempt);
                $this->client->request('PUT', "/{$resource}/{$attempt->remote_draft_id}/change-state.json", payload: [
                    'invoice' => ['state' => 'finalized'],
                ]);
                $document = $this->getDocument($attempt);
            } catch (InvoiceXpressException) {
                return $attempt;
            }

            if (! $this->matches($attempt, $document)) {
                return $this->block($attempt);
            }

            return match ($document['status']) {
                $this->finalStatus($attempt) => $this->saveFinal($attempt, $document),
                'draft' => $attempt,
                default => $this->block($attempt),
            };
        });
    }

    private function snapshot(
        int $receivableId,
        int $customerId,
        int $itemId,
        int $quantity,
        array $additionalLines,
        string $documentType,
        ?string $exemptionReason,
        bool $forNewIssue = true,
        bool $allowAdvancePreview = false,
    ): array {
        $options = self::options($forNewIssue);
        if ($options === null) {
            throw ValidationException::withMessages(['item' => 'Escolhe um artigo aprovado para a emissão assistida.']);
        }

        if (! isset(self::DocumentTypes[$documentType])) {
            throw ValidationException::withMessages(['documentType' => 'Escolhe um tipo de documento suportado.']);
        }

        $receipt = $documentType === 'invoice_receipt';
        $selection = InvoiceXpressIssueTerms::lineSelections($itemId, $quantity, $additionalLines, $options['itemIds'], self::MaxLines);

        $receivable = Receivable::query()->excludingCancelled()->lockForUpdate()->with('billable.client')->find($receivableId);
        $billable = $receivable?->billable;
        $localClient = $receivable?->client;
        $issueOn = today();
        $rate = match ($billable?->vat_rate) {
            '0.0000' => 0,
            '0.0600' => 600,
            '0.1300' => 1300,
            '0.2300' => 2300,
            default => null,
        };

        if (! ($billable instanceof Project || $billable instanceof Retainer)
            || $localClient?->fiscal_country_code !== 'PT' || $localClient->fiscal_party_type === null
            || InvoiceXpressIssueTerms::nif($localClient->vat_number) === null
            || $rate === null
            || (! $receipt && ($receivable->due_on === null || $receivable->due_on->lt($issueOn)))) {
            throw ValidationException::withMessages(['receivable' => 'Escolhe um valor a receber com cliente fiscal português, IVA suportado, vencimento válido e sem referência de faturação anterior.']);
        }

        $advances = $receivable->payments()->whereNotNull('payments.advance_client_id')->limit(2)->get();
        if ($advances->isNotEmpty() && ! $allowAdvancePreview) {
            throw ValidationException::withMessages(['confirmation' => InvoiceXpressIssueAdvance::ProviderBlock]);
        }

        if ($advances->count() > 1 || ($advances->isNotEmpty() && $documentType !== 'invoice')) {
            throw ValidationException::withMessages(['advanceSettlement' => 'O acerto assistido é para uma fatura com um único adiantamento afeto à cobrança.']);
        }

        if (! $receipt && $receivable->payments()->whereNull('payments.advance_client_id')
            ->whereNotNull('invoice_number')->where('invoice_number', '!=', '')->exists()) {
            throw ValidationException::withMessages(['receivable' => 'Escolhe um valor a receber sem referência de faturação anterior.']);
        }

        $profile = FiscalProfile::effectiveOn($issueOn);
        $exempt = $profile?->vat_exempt ?? app(FinanceSettings::class)->vatExempt();
        if ($rate === 0
            ? $exemptionReason !== 'M10' || $profile?->vat_exempt !== true || $profile->country_code !== 'PT'
            : $exemptionReason !== null || $exempt) {
            throw ValidationException::withMessages(['exemptionReason' => 'Para usar a isenção do artigo 53.º, confirma o enquadramento fiscal em Portugal, escolhe M10 e usa uma cobrança com IVA a 0%.']);
        }

        if ($customerId < 1) {
            throw ValidationException::withMessages(['customer' => 'Indica o identificador de um cliente já existente no InvoiceXpress.']);
        }

        $existing = InvoiceXpressIssueAttempt::where('intent_key', "receivable:{$receivableId}")->first();

        if ($this->hasBillingDocument($receivableId, $advances->isEmpty() ? $existing?->fiscal_document_id : null)) {
            throw ValidationException::withMessages(['receivable' => 'Já existe um documento de faturação ativo ligado a este valor a receber.']);
        }

        if ($advances->isNotEmpty() && in_array($existing?->state, InvoiceXpressIssueAttempt::Recoverable, true)) {
            throw ValidationException::withMessages(['advanceSettlement' => 'Verifica a tentativa de emissão existente antes de preparar o acerto do adiantamento.']);
        }

        $advance = $advances->isEmpty() ? null : InvoiceXpressIssueAdvance::source($receivable, $advances->first(), $rate, $issueOn->toDateString());
        $payment = $receipt ? $this->receiptPayment($receivable, $issueOn->toDateString(), $existing?->fiscal_document_id) : null;
        $customer = InvoiceXpressIssueTerms::customer(
            $this->envelope($this->client->request('GET', "/clients/{$customerId}.json"), 'client'),
            $customerId,
            $localClient->vat_number,
        );
        $tax = InvoiceXpressIssueTerms::tax(
            $this->envelope($this->client->request('GET', '/taxes.json'), 'taxes'), $options['taxName'], 'PT', $rate,
        );

        $totals = ['net' => 0, 'tax' => 0, 'total' => 0];
        $lines = [];
        $items = [];
        $names = [];

        foreach ($selection as ['itemId' => $id, 'quantity' => $lineQuantity]) {
            $remoteItem = $this->envelope($this->client->request('GET', "/items/{$id}.json"), 'item');
            $item = InvoiceXpressIssueTerms::item($remoteItem, $id);

            if (data_get($remoteItem, 'tax.name') !== $tax['name']
                || (data_get($remoteItem, 'tax.id') !== null && InvoiceXpressIssueTerms::positiveId(data_get($remoteItem, 'tax.id')) !== $tax['id'])
                || InvoiceXpressIssueTerms::hundredths(data_get($remoteItem, 'tax.value')) !== $rate) {
                throw ValidationException::withMessages(['item' => 'Cada artigo aprovado deve já ter o mesmo IVA configurado para a emissão.']);
            }

            $name = mb_strtolower($item['name']);
            if (isset($names[$name])) {
                throw ValidationException::withMessages(['item' => 'Escolhe artigos com nomes distintos no catálogo do InvoiceXpress.']);
            }
            $names[$name] = true;

            $lineTotals = InvoiceXpressIssueTerms::totals($item['unitCents'], $lineQuantity, $rate);
            foreach ($lineTotals as $field => $amount) {
                $totals[$field] += $amount;
            }
            $lines[] = ['item' => $item, 'quantity' => $lineQuantity, 'totals' => $lineTotals];
            $items[] = [
                'name' => $item['name'],
                'description' => $item['description'],
                'unit' => $item['unit'],
                'unit_price' => $item['unitCents'] / 100,
                'quantity' => $lineQuantity,
                'tax' => ['name' => $tax['name']],
            ];
        }

        if ($totals['total'] !== $receivable->amount) {
            throw ValidationException::withMessages(['quantity' => 'O total com IVA das linhas tem de coincidir com o valor a receber.']);
        }

        if ($advance !== null) {
            return [
                'customer' => $customer,
                'tax' => $tax,
                'lines' => $lines,
                'totals' => $totals,
                'advanceSettlement' => InvoiceXpressIssueAdvance::amounts($advance, $totals, $rate),
            ];
        }

        if ($documentType === 'simplified_invoice' && $exemptionReason === null && $totals['total'] > 10000) {
            throw ValidationException::withMessages(['documentType' => 'A fatura simplificada de serviços com IVA só pode ser emitida até 100,00 €.']);
        }

        $sequenceId = $options['sequenceId'];
        $sequence = InvoiceXpressIssueTerms::sequence(
            $this->envelope($this->client->request('GET', "/sequences/{$sequenceId}.json"), 'sequence'), $sequenceId, $documentType,
        );

        return [
            'receivableId' => $receivableId,
            'localClientId' => $localClient->id,
            'issueOn' => $issueOn->toDateString(),
            'customer' => $customer,
            'item' => $lines[0]['item'],
            'tax' => $tax,
            'sequence' => $sequence,
            'totals' => $totals,
            'invoice' => [
                'date' => $issueOn->format('d/m/Y'),
                'due_date' => ($receipt ? $issueOn : $receivable->due_on)->format('d/m/Y'),
                'reference' => "Contiu #{$receivableId}",
                'sequence_id' => (string) $sequence['id'],
                'currency_code' => 'EUR',
                'mb_reference' => '0',
                'client' => ['id' => $customerId],
                'items' => $items,
                ...($exemptionReason === null ? [] : ['tax_exemption_reason' => $exemptionReason]),
            ],
            ...($documentType !== 'invoice' || count($lines) > 1 ? ['documentType' => $documentType, 'lines' => $lines] : []),
            ...($payment === null ? [] : ['payment' => $payment]),
        ];
    }

    /** @return array{id: int, paidOn: string, amount: int} */
    private function receiptPayment(Receivable $receivable, string $issueOn, ?int $fiscalDocumentId): array
    {
        $payments = $receivable->payments()->lockForUpdate()->get();
        $payment = $payments->first();

        if ($payments->count() !== 1 || $payment->is_split || $payment->isAdvance()
            || (int) $payment->receivable_id !== $receivable->id || filled($payment->invoice_number)
            || $payment->amount !== $receivable->amount || (int) $payment->pivot->amount !== $receivable->amount
            || $payment->paid_on->toDateString() !== $issueOn || substr((string) $payment->pivot->allocated_on, 0, 10) !== $issueOn
            || $payment->allocations()->where('receivable_id', '!=', $receivable->id)->exists()
            || $receivable->creditApplications()->effectiveAt($issueOn)->exists()
            || FiscalDocumentLink::query()->where('payment_id', $payment->id)
                ->when($fiscalDocumentId !== null, fn ($query) => $query->where('fiscal_document_id', '!=', $fiscalDocumentId))
                ->whereHas('fiscalDocument', fn ($query) => $query->where('status', FiscalDocumentStatus::Active))
                ->exists()) {
            throw ValidationException::withMessages(['payment' => 'A fatura-recibo exige um único recebimento integral registado no dia da emissão, sem repartição, crédito aplicado ou documento fiscal associado.']);
        }

        return ['id' => $payment->id, 'paidOn' => $issueOn, 'amount' => $payment->amount];
    }

    private function hasBillingDocument(int $receivableId, ?int $fiscalDocumentId): bool
    {
        return FiscalDocumentLink::query()->where('receivable_id', $receivableId)
            ->when($fiscalDocumentId !== null, fn ($query) => $query->where('fiscal_document_id', '!=', $fiscalDocumentId))
            ->whereHas('fiscalDocument', fn ($query) => $query
                ->where('status', FiscalDocumentStatus::Active)->where('direction', FiscalDocumentDirection::Issued)
                ->whereIn('type', [FiscalDocumentType::Invoice, FiscalDocumentType::InvoiceReceipt, FiscalDocumentType::DebitNote]))
            ->exists();
    }

    private function stillCurrent(InvoiceXpressIssueAttempt $attempt): bool
    {
        $snapshot = $attempt->snapshot;

        try {
            return $snapshot === $this->snapshot(
                $snapshot['receivableId'],
                $snapshot['customer']['id'],
                $snapshot['item']['id'],
                $snapshot['invoice']['items'][0]['quantity'],
                array_map(fn (array $line): array => ['itemId' => $line['item']['id'], 'quantity' => $line['quantity']], array_slice($snapshot['lines'] ?? [], 1)),
                $snapshot['documentType'] ?? 'invoice',
                $snapshot['invoice']['tax_exemption_reason'] ?? null,
                forNewIssue: false,
            );
        } catch (ValidationException) {
            return false;
        }
    }

    private function revision(array $snapshot): string
    {
        return hash_hmac('sha256', json_encode([
            CurrentAccount::id(), auth()->id(), $this->target(), $snapshot,
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function target(): string
    {
        return hash('sha256', $this->client->configuration()->subdomain);
    }

    private function assertTarget(InvoiceXpressIssueAttempt $attempt): void
    {
        if ($attempt->provider_target !== $this->target()) {
            throw ValidationException::withMessages(['confirmation' => 'A tentativa pertence a outra conta InvoiceXpress. Repõe a ligação original para a consultar.']);
        }
    }

    private function getDocument(InvoiceXpressIssueAttempt $attempt): array
    {
        $resource = $this->resource($attempt);

        return $this->envelope(
            $this->client->request('GET', "/{$resource}/{$attempt->remote_draft_id}.json"),
            $attempt->snapshot['documentType'] ?? 'invoice',
        );
    }

    private function resource(InvoiceXpressIssueAttempt $attempt): string
    {
        return ($attempt->snapshot['documentType'] ?? 'invoice').'s';
    }

    private function finalStatus(InvoiceXpressIssueAttempt $attempt): string
    {
        return $this->resource($attempt) === 'invoice_receipts' ? 'settled' : 'final';
    }

    private function envelope(array $response, string $key): array
    {
        $result = $response[$key] ?? null;

        if (! is_array($result)) {
            throw new InvoiceXpressException('O InvoiceXpress devolveu dados incompletos.');
        }

        return $result;
    }

    private function matches(InvoiceXpressIssueAttempt $attempt, array $document): bool
    {
        $snapshot = $attempt->snapshot;
        $invoice = $snapshot['invoice'];
        $totals = $snapshot['totals'];
        $tax = $snapshot['tax'];
        $items = $document['items'] ?? [];
        $remoteCustomer = $document['client'] ?? [];

        try {
            if (! is_array($remoteCustomer) || InvoiceXpressIssueTerms::customer(
                $remoteCustomer, $snapshot['customer']['id'], $snapshot['customer']['nif'],
            ) !== $snapshot['customer']) {
                return false;
            }
        } catch (ValidationException) {
            return false;
        }

        return InvoiceXpressIssueTerms::positiveId($document['id'] ?? null) === $attempt->remote_draft_id
            && is_string($document['status'] ?? null)
            && ($document['type'] ?? null) === match ($this->resource($attempt)) {
                'invoice_receipts' => 'InvoiceReceipt',
                'simplified_invoices' => 'SimplifiedInvoice',
                default => 'Invoice',
            }
        && InvoiceXpressIssueTerms::positiveId($document['sequence_id'] ?? null) === $snapshot['sequence']['id']
        && in_array($document['currency'] ?? null, ['EUR', 'Euro'], true)
        && ($document['date'] ?? null) === $invoice['date']
        && ($document['due_date'] ?? null) === $invoice['due_date']
        && ($document['reference'] ?? null) === $invoice['reference']
        && InvoiceXpressIssueTerms::hundredths($document['before_taxes'] ?? null) === $totals['net']
        && InvoiceXpressIssueTerms::hundredths($document['taxes'] ?? null) === $totals['tax']
        && InvoiceXpressIssueTerms::hundredths($document['total'] ?? null) === $totals['total']
        && InvoiceXpressIssueTerms::hundredths($document['discount'] ?? 0) === 0
        && InvoiceXpressIssueTerms::hundredths($document['retention'] ?? 0) === 0
        && in_array($document['tax_exemption'] ?? null, isset($invoice['tax_exemption_reason'])
            ? [$invoice['tax_exemption_reason']]
            : [null, '', 'M00'], true)
        && $this->matchesLines($items, $snapshot, $tax);
    }

    private function matchesLines(mixed $items, array $snapshot, array $tax): bool
    {
        $expectedItems = $snapshot['invoice']['items'];
        if (! is_array($items) || ! array_is_list($items) || count($items) !== count($expectedItems)) {
            return false;
        }

        foreach ($expectedItems as $index => $expected) {
            $details = $snapshot['lines'][$index] ?? $snapshot;
            if (! is_array($items[$index]) || ! InvoiceXpressIssueTerms::matchesLine($items[$index], $expected, $details['item'], $tax, $details['totals'])) {
                return false;
            }
        }

        return true;
    }

    private function saveFinal(InvoiceXpressIssueAttempt $attempt, array $document): InvoiceXpressIssueAttempt
    {
        $number = $document['inverted_sequence_number'] ?? null;
        $atcud = $document['atcud'] ?? null;
        $snapshot = $attempt->snapshot;
        $series = preg_quote($snapshot['sequence']['name'], '#');

        if (! is_string($number) || ! preg_match("#\A{$series}/([1-9][0-9]{0,18})\z#", $number, $parts)
            || $atcud !== $snapshot['sequence']['validationCode'].'-'.$parts[1]) {
            return $this->block($attempt);
        }

        $subdomain = $this->client->configuration()->subdomain;

        $payment = $snapshot['payment'] ?? null;

        try {
            $review = $this->documents->reviewInvoice($document, $subdomain);

            if (! in_array($review['state'], ['new', 'same'], true)) {
                return $this->block($attempt);
            }

            $receivable = Receivable::query()->lockForUpdate()->find($snapshot['receivableId']);
            if ($payment !== null) {
                $knownDocumentId = InvoiceXpressSnapshot::query()->where('subdomain', $subdomain)
                    ->where('provider_id', $attempt->remote_draft_id)->latest('id')->value('fiscal_document_id');
                if ($receivable === null || $this->hasBillingDocument($receivable->id, $knownDocumentId)
                    || $this->receiptPayment($receivable, $snapshot['issueOn'], $knownDocumentId) !== $payment) {
                    return $this->block($attempt);
                }
            }

            $record = $this->documents->confirmInvoice($review['invoice'], expectedSubdomain: $subdomain);
        } catch (ValidationException) {
            return $this->block($attempt);
        }

        $existingLink = $record->links()->lockForUpdate()->first();
        if (($receivable !== null && $existingLink?->receivable_id !== null && (int) $existingLink->receivable_id !== $receivable->id)
            || ($payment !== null && $existingLink?->payment_id !== null && (int) $existingLink->payment_id !== $payment['id'])) {
            return $this->block($attempt);
        }

        if ($receivable !== null && ($existingLink?->receivable_id === null || ($payment !== null && $existingLink->payment_id === null))) {
            $record->links()->updateOrCreate([], [
                'receivable_id' => $receivable->id,
                ...($payment === null ? [] : ['payment_id' => $payment['id']]),
            ]);
        }

        $prefix = match ($this->resource($attempt)) {
            'invoice_receipts' => 'FR',
            'simplified_invoices' => 'FS',
            default => 'FT',
        };
        $attempt->forceFill([
            'state' => InvoiceXpressIssueAttempt::Finalized,
            'provider_invoice_id' => $attempt->remote_draft_id,
            'document_reference' => "{$prefix} {$number}",
            'atcud' => $atcud,
            'fiscal_document_id' => $record->id,
            'finalized_at' => now(),
        ])->save();

        return $attempt;
    }

    private function block(InvoiceXpressIssueAttempt $attempt): InvoiceXpressIssueAttempt
    {
        $attempt->forceFill(['state' => InvoiceXpressIssueAttempt::Blocked])->save();

        return $attempt;
    }
}
