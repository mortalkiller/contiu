<?php

namespace App\Support;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Enums\FiscalProfilePartyType;
use App\Exceptions\InvoiceXpressException;
use App\Models\Account;
use App\Models\Client;
use App\Models\FiscalDocumentLink;
use App\Models\FiscalProfile;
use App\Models\InvoiceXpressConfig;
use App\Models\InvoiceXpressIssueAttempt;
use App\Models\InvoiceXpressSnapshot;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\Retainer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class InvoiceXpressIssue
{
    public const string SettingsKey = InvoiceXpressIssueSettings::Key;

    public const int MaxLines = 10;

    public const array DocumentTypes = [
        'invoice' => 'Invoice',
        'invoice_receipt' => 'Invoice receipt',
        'simplified_invoice' => 'Simplified invoice',
    ];

    public function __construct(
        private readonly InvoiceXpressClient $client,
        private readonly InvoiceXpressIssueRepository $attempts,
        private readonly InvoiceXpressImport $documents,
    ) {}

    /** @return array{itemIds: list<int>, taxName: string, sequenceId: int, draftOnly?: true}|null */
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
        $draftOnly = ($settings['draft_only'] ?? false) === true;

        if ($config === null || ($settings['subdomain'] ?? null) !== $config->subdomain
            || ($forNewIssue && ($settings['user_id'] ?? null) !== (int) auth()->id())
            || ($settings['provider'] ?? null) !== 'invoicexpress' || ! is_array($ids) || ! array_is_list($ids) || $ids === []
            || ! is_string($tax) || $tax === '' || mb_strlen($tax) > 255
            || ! is_int($sequenceId) || $sequenceId < 1
            || ($draftOnly && ! InvoiceXpressClient::sandboxDraftsAllowed($config->subdomain))
            || array_filter($ids, fn (mixed $id): bool => ! is_int($id) || $id < 1) !== []) {
            return null;
        }

        return ['itemIds' => array_values(array_unique($ids)), 'taxName' => $tax, 'sequenceId' => $sequenceId,
            ...($draftOnly ? ['draftOnly' => true] : [])];
    }

    public static function canWriteDocuments(): bool
    {
        $subdomain = InvoiceXpressConfig::current()?->subdomain;

        return $subdomain !== null && InvoiceXpressClient::catalogValidatedFor($subdomain);
    }

    public static function canWriteWithholding(): bool
    {
        $subdomain = InvoiceXpressConfig::current()?->subdomain;

        return $subdomain !== null && in_array($subdomain, config('services.invoicexpress.withholding_validated_accounts', []), true);
    }

    private function requireCatalogValidation(int $withholdingBasisPoints = 0): void
    {
        if (! self::canWriteDocuments()) {
            throw ValidationException::withMessages(['confirmation' => __('Issuance is pending confirmation that the API preserves the InvoiceXpress catalog.')]);
        }

        if ($withholdingBasisPoints !== 0 && ! self::canWriteWithholding()) {
            throw ValidationException::withMessages(['confirmation' => __('Issuance is pending validation of withholding tax in the InvoiceXpress sandbox.')]);
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
        int $discountBasisPoints = 0,
        ?array $direct = null,
        ?string $euOperationOn = null,
        int $withholdingBasisPoints = 0,
    ): array {
        abort_unless(self::options() !== null, 403);

        return DB::transaction(function () use ($receivableId, $customerId, $itemId, $quantity, $additionalLines, $documentType, $exemptionReason, $discountBasisPoints, $direct, $euOperationOn, $withholdingBasisPoints): array {
            Account::query()->lockForUpdate()->findOrFail(CurrentAccount::id());
            $snapshot = $this->snapshot($receivableId, $customerId, $itemId, $quantity, $additionalLines, $documentType, $exemptionReason,
                allowAdvancePreview: true, discountBasisPoints: $discountBasisPoints, direct: $direct, euOperationOn: $euOperationOn, withholdingBasisPoints: $withholdingBasisPoints);

            return isset($snapshot['advanceSettlement']) ? $snapshot : [...$snapshot, 'revision' => $this->revision($snapshot)];
        });
    }

    public function createDraft(array $inputs, string $revision, InvoiceXpressSandboxDrafts $drafts): int
    {
        abort_unless(self::options()['draftOnly'] ?? false, 403);
        $snapshot = $this->preview(...$inputs);
        if (! isset($snapshot['revision'], $snapshot['draftAccount']) || ! hash_equals($snapshot['revision'], $revision)) {
            throw ValidationException::withMessages(['confirmation' => __('The details have changed. Preview the document again.')]);
        }

        $itemIds = array_map(fn (array $line): int => $line['item']['id'], $snapshot['lines'] ?? [['item' => $snapshot['item']]]);

        return $drafts->create($snapshot['draftAccount'], $snapshot['documentType'] ?? 'invoice',
            $this->providerInvoice($snapshot['invoice']), $itemIds, $revision, $snapshot['totals'], $snapshot['withholding'] ?? null);
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
        int $discountBasisPoints = 0,
        ?array $direct = null,
        ?string $euOperationOn = null,
        int $withholdingBasisPoints = 0,
    ): InvoiceXpressIssueAttempt {
        abort_unless(self::options() !== null, 403);

        $attempt = DB::transaction(function () use ($receivableId, $customerId, $itemId, $quantity, $revision, $additionalLines, $documentType, $exemptionReason, $discountBasisPoints, $direct, $euOperationOn, $withholdingBasisPoints): InvoiceXpressIssueAttempt {
            Account::query()->lockForUpdate()->findOrFail(CurrentAccount::id());
            if (self::options()['draftOnly'] ?? false) {
                throw ValidationException::withMessages(['confirmation' => __('The selected series only allows draft creation.')]);
            }
            $this->requireCatalogValidation($withholdingBasisPoints);
            $snapshot = $this->snapshot($receivableId, $customerId, $itemId, $quantity, $additionalLines, $documentType, $exemptionReason,
                discountBasisPoints: $discountBasisPoints, direct: $direct, euOperationOn: $euOperationOn, withholdingBasisPoints: $withholdingBasisPoints);

            if (! hash_equals($this->revision($snapshot), $revision)) {
                throw ValidationException::withMessages(['confirmation' => __('The details have changed. Preview the document again before confirming.')]);
            }

            $intent = $direct === null ? "receivable:{$receivableId}" : 'direct:'.$snapshot['direct']['key'];
            $existing = InvoiceXpressIssueAttempt::query()->where('intent_key', $intent)->first();
            if ($direct !== null && $existing === null) {
                $this->rejectUnresolvedDirectInvoice($snapshot);
            }
            if ($existing !== null && $this->withLegacyInvoice($snapshot) === $existing->snapshot) {
                $snapshot = $existing->snapshot;
            }

            return $this->attempts->reserve($intent, $this->target(), $snapshot, (int) auth()->id());
        });

        return $this->recover($attempt->id);
    }

    private function rejectUnresolvedDirectInvoice(array $snapshot): void
    {
        $invoice = Arr::except($snapshot['invoice'], ['date', 'reference']);
        $attempts = InvoiceXpressIssueAttempt::query()->where('intent_key', 'like', 'direct:%')
            ->whereIn('state', [...InvoiceXpressIssueAttempt::Recoverable, InvoiceXpressIssueAttempt::Blocked])->cursor();

        foreach ($attempts as $attempt) {
            $prior = $attempt->snapshot;
            if (($prior['direct']['clientId'] ?? null) === $snapshot['direct']['clientId']
                && Arr::except($prior['invoice'], ['date', 'reference']) === $invoice) {
                throw ValidationException::withMessages(['confirmation' => __('An unresolved attempt already exists with these details. Review attempt #:id.', ['id' => $attempt->id])]);
            }
        }
    }

    public function recover(int $attemptId): InvoiceXpressIssueAttempt
    {
        abort_unless(InvoiceXpressIssueRepository::ownerCanIssue(), 403);

        $attempt = $this->attempts->locked($attemptId, function (InvoiceXpressIssueAttempt $attempt): InvoiceXpressIssueAttempt {
            $this->assertTarget($attempt);

            if ($attempt->state !== InvoiceXpressIssueAttempt::Pending) {
                return $attempt;
            }

            $this->requireCatalogValidation($attempt->snapshot['withholding']['basisPoints'] ?? 0);

            try {
                if (! $this->stillCurrent($attempt)) {
                    return $this->block($attempt);
                }

                $resource = $this->resource($attempt);
                $key = $attempt->snapshot['documentType'] ?? 'invoice';
                $response = $this->client->request('POST', "/{$resource}.json", payload: [
                    'proprietary_uid' => $attempt->proprietary_uid,
                    $key => $this->providerInvoice($attempt->snapshot['invoice']),
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

                $this->requireCatalogValidation($attempt->snapshot['withholding']['basisPoints'] ?? 0);

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
        int $discountBasisPoints = 0,
        ?array $direct = null,
        ?string $euOperationOn = null,
        int $withholdingBasisPoints = 0,
    ): array {
        $options = self::options($forNewIssue);
        if ($options === null) {
            throw ValidationException::withMessages(['item' => __('Choose an approved item for assisted issuance.')]);
        }

        $draftOnly = $options['draftOnly'] ?? false;
        if (! isset(self::DocumentTypes[$documentType]) || ($draftOnly && ! isset(InvoiceXpressSandboxDrafts::DocumentTypes[$documentType]))) {
            throw ValidationException::withMessages(['documentType' => __('Choose a supported document type.')]);
        }

        $receipt = $documentType === 'invoice_receipt';
        $selection = InvoiceXpressIssueTerms::lineSelections($itemId, $quantity, $additionalLines, $options['itemIds'], self::MaxLines, $discountBasisPoints);

        $issueOn = today();
        if ($direct !== null) {
            if ($receivableId !== 0 || (! $draftOnly && $documentType !== 'invoice')) {
                throw ValidationException::withMessages(['documentType' => __('Direct issuance is available for invoices with payment due later.')]);
            }

            Validator::make(['direct' => $direct], [
                'direct' => ['array:key,clientId,dueOn'],
                'direct.key' => ['required', 'uuid'],
                'direct.clientId' => ['required', 'integer', 'min:1'],
                'direct.dueOn' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$issueOn->toDateString()],
            ])->validate();

            $direct = ['key' => strtolower($direct['key']), 'clientId' => (int) $direct['clientId'], 'dueOn' => $direct['dueOn']];
        }

        $receivable = $direct === null ? Receivable::query()->excludingCancelled()->lockForUpdate()->with('billable.client')->find($receivableId) : null;
        $billable = $receivable?->billable;
        $localClient = $direct === null ? $receivable?->client : Client::query()->lockForUpdate()->find($direct['clientId']);
        $rate = match ($billable?->vat_rate) {
            '0.0000' => 0,
            '0.0600' => 600,
            '0.1300' => 1300,
            '0.2300' => 2300,
            default => null,
        };

        if ($localClient === null || ($direct === null && (! ($billable instanceof Project || $billable instanceof Retainer)
            || $rate === null || (! $receipt && ($receivable->due_on === null || $receivable->due_on->lt($issueOn)))))) {
            throw ValidationException::withMessages([$direct === null ? 'receivable' : 'localClientId' => __('Choose a client or a receivable with valid tax details and a valid due date.')]);
        }

        $eu = null;
        if ($euOperationOn !== null) {
            if ($documentType !== 'invoice' || $exemptionReason !== null || ($receivable !== null && $rate !== 0)) {
                throw ValidationException::withMessages(['exemptionReason' => __('Services to EU businesses require an invoice with payment due later. Any associated receivable must be set up without Portuguese VAT.')]);
            }

            $eu = InvoiceXpressEuService::terms($localClient, $euOperationOn);
            $rate = 0;
            $exemptionReason = 'M40';
        } elseif (! $draftOnly && ($localClient->fiscal_country_code !== 'PT' || $localClient->fiscal_party_type === null
            || InvoiceXpressIssueTerms::nif($localClient->vat_number) === null)) {
            throw ValidationException::withMessages(['customer' => __('Choose a client with a Portuguese tax ID or select the tax treatment for services to EU businesses.')]);
        }

        if ($withholdingBasisPoints !== 0 && ($eu !== null || $documentType === 'simplified_invoice'
            || (! $draftOnly && $localClient->fiscal_party_type !== FiscalProfilePartyType::Business))) {
            throw ValidationException::withMessages(['withholdingRate' => __('Assisted withholding is available for invoices and invoice receipts for services to businesses in Portugal.')]);
        }

        $advances = $receivable?->payments()->whereNotNull('payments.advance_client_id')->limit(2)->get() ?? collect();
        if ($advances->isNotEmpty() && ! $allowAdvancePreview) {
            throw ValidationException::withMessages(['confirmation' => __(InvoiceXpressIssueAdvance::ProviderBlock)]);
        }

        if ($advances->count() > 1 || ($advances->isNotEmpty() && ($documentType !== 'invoice' || $eu !== null))) {
            throw ValidationException::withMessages(['advanceSettlement' => __('Assisted advance settlement requires an invoice with a single advance allocated to the receivable.')]);
        }

        if ($receivable !== null && ! $receipt && $receivable->payments()->whereNull('payments.advance_client_id')
            ->whereNotNull('invoice_number')->where('invoice_number', '!=', '')->exists()) {
            throw ValidationException::withMessages(['receivable' => __('Choose a receivable without a previous invoice reference.')]);
        }

        if ($customerId < 1) {
            throw ValidationException::withMessages(['customer' => __('Enter the ID of an existing InvoiceXpress customer.')]);
        }

        $existing = $receivable === null ? null : InvoiceXpressIssueAttempt::where('intent_key', "receivable:{$receivableId}")->first();

        if ($receivable !== null && $this->hasBillingDocument($receivableId, $advances->isEmpty() ? $existing?->fiscal_document_id : null)) {
            throw ValidationException::withMessages(['receivable' => __('An active billing document is already linked to this receivable.')]);
        }

        if ($advances->isNotEmpty() && in_array($existing?->state, InvoiceXpressIssueAttempt::Recoverable, true)) {
            throw ValidationException::withMessages(['advanceSettlement' => __('Check the existing issuance attempt before preparing the advance settlement.')]);
        }

        $taxName = $options['taxName'];
        $firstItem = null;
        if ($eu !== null || $rate === null) {
            $firstId = $selection[0]['itemId'];
            $firstItem = $this->envelope($this->client->request('GET', "/items/{$firstId}.json"), 'item');
            if ($eu !== null) {
                $taxName = data_get($firstItem, 'tax.name');
            }
            $rate ??= InvoiceXpressIssueTerms::hundredths(data_get($firstItem, 'tax.value'));
        }

        if (! is_string($taxName) || $taxName === '' || ! in_array($rate, [0, 600, 1300, 2300], true)) {
            throw ValidationException::withMessages(['tax' => __('Choose an item with a supported VAT rate.')]);
        }

        if ($eu === null) {
            $profile = FiscalProfile::effectiveOn($issueOn);
            $exempt = $profile?->vat_exempt ?? app(FinanceSettings::class)->vatExempt();
            if ($rate === 0
                ? $exemptionReason !== 'M10' || (! $draftOnly && ($profile?->vat_exempt !== true || $profile->country_code !== 'PT'))
                : $exemptionReason !== null || (! $draftOnly && $exempt)) {
                throw ValidationException::withMessages(['exemptionReason' => __('To use the Article 53 exemption, confirm the Portuguese tax regime, choose M10 and use a line with 0% VAT.')]);
            }
        }

        $advance = $advances->isEmpty() ? null : InvoiceXpressIssueAdvance::source($receivable, $advances->first(), $rate, $issueOn->toDateString());
        $payment = $receipt && ! $draftOnly && $withholdingBasisPoints === 0 ? $this->receiptPayment($receivable, $issueOn->toDateString(), $existing?->fiscal_document_id) : null;
        $remoteCustomer = $this->envelope($this->client->request('GET', "/clients/{$customerId}.json"), 'client');
        $customer = match (true) {
            $eu !== null => InvoiceXpressEuService::customer($remoteCustomer, $customerId, $eu),
            $draftOnly => InvoiceXpressSandboxDrafts::customer($remoteCustomer, $customerId),
            default => InvoiceXpressIssueTerms::customer($remoteCustomer, $customerId, $localClient->vat_number),
        };
        $tax = InvoiceXpressIssueTerms::tax($this->envelope($this->client->request('GET', '/taxes.json'), 'taxes'), $taxName, 'PT', $rate, draftOnly: $draftOnly);

        $totals = ['net' => 0, 'tax' => 0, 'total' => 0];
        $lines = [];
        $items = [];
        $names = [];

        foreach ($selection as $index => $selected) {
            ['itemId' => $id, 'quantity' => $lineQuantity] = $selected;
            $discount = $selected['discountBasisPoints'] ?? 0;
            $remoteItem = $index === 0 && $firstItem !== null ? $firstItem : $this->envelope($this->client->request('GET', "/items/{$id}.json"), 'item');
            $item = InvoiceXpressIssueTerms::item($remoteItem, $id);

            if (data_get($remoteItem, 'tax.name') !== $tax['name']
                || (data_get($remoteItem, 'tax.id') !== null && InvoiceXpressIssueTerms::positiveId(data_get($remoteItem, 'tax.id')) !== $tax['id'])
                || InvoiceXpressIssueTerms::hundredths(data_get($remoteItem, 'tax.value')) !== $rate) {
                throw ValidationException::withMessages(['item' => __('Each approved item must already have the same VAT rate configured for issuance.')]);
            }

            $name = mb_strtolower($item['name']);
            if (isset($names[$name])) {
                throw ValidationException::withMessages(['item' => __('Choose items with distinct names in the InvoiceXpress catalog.')]);
            }
            $names[$name] = true;

            $lineTotals = InvoiceXpressIssueTerms::totals($item['unitCents'], $lineQuantity, $rate, $discount);
            foreach ($lineTotals as $field => $amount) {
                $totals[$field] = ($totals[$field] ?? 0) + $amount;
            }
            $lines[] = ['item' => $item, 'quantity' => $lineQuantity, 'totals' => $lineTotals];
            $items[] = [
                'name' => $item['name'],
                'description' => $item['description'],
                'unit' => $item['unit'],
                'unit_price_cents' => $item['unitCents'],
                'quantity' => $lineQuantity,
                'tax' => ['name' => $tax['name']],
                ...($discount === 0 ? [] : ['discount_basis_points' => $discount]),
            ];
        }

        if ($totals['total'] <= 0 || ($receivable !== null && $totals['total'] !== $receivable->amount)) {
            throw ValidationException::withMessages(['quantity' => __('The line total including VAT must match the receivable amount.')]);
        }

        if ($advance !== null) {
            $settlement = InvoiceXpressIssueAdvance::amounts($advance, $totals, $rate);
            $cash = $advances->first()->amount;
            if ($cash < $advance->total_amount) {
                $settlement['received'] = ['cash' => $cash, 'retained' => $advance->total_amount - $cash];
            }
            $withholding = InvoiceXpressIssueTerms::withholding($withholdingBasisPoints, $settlement['balance']['net'], $settlement['balance']['total']);

            return [
                'customer' => $customer,
                'tax' => $tax,
                'lines' => $lines,
                'totals' => $totals,
                'advanceSettlement' => $settlement,
                ...($withholding === null ? [] : ['withholding' => $withholding]),
            ];
        }

        $withholding = InvoiceXpressIssueTerms::withholding($withholdingBasisPoints, $totals['net'], $totals['total']);
        if ($receipt && ! $draftOnly && $withholding !== null) {
            $payment = $this->receiptPayment($receivable, $issueOn->toDateString(), $existing?->fiscal_document_id, $withholding);
        }

        if ($documentType === 'simplified_invoice' && $exemptionReason === null && $totals['total'] > 10000) {
            throw ValidationException::withMessages(['documentType' => __('A simplified invoice for services with VAT can only be issued up to €100.00.')]);
        }

        $sequenceId = $options['sequenceId'];
        $draftAccount = $draftOnly ? $this->client->configuration()->subdomain : null;
        $remoteSeries = $this->envelope($this->client->request('GET', "/sequences/{$sequenceId}.json", expectedSubdomain: $draftAccount), 'sequence');
        $sequence = $draftOnly
            ? InvoiceXpressSandboxDrafts::sequence($remoteSeries, $sequenceId, $documentType)
            : InvoiceXpressIssueTerms::sequence($remoteSeries, $sequenceId, $documentType);

        return [
            'receivableId' => $receivableId,
            'localClientId' => $localClient->id,
            ...($draftAccount === null ? [] : ['draftAccount' => $draftAccount]),
            ...($direct === null ? [] : ['direct' => $direct]),
            ...($eu === null ? [] : ['euService' => $eu, 'euOperationOn' => $euOperationOn]),
            'issueOn' => $issueOn->toDateString(),
            'customer' => $customer,
            'item' => $lines[0]['item'],
            'tax' => $tax,
            'sequence' => $sequence,
            'totals' => $totals,
            'invoice' => [
                'date' => $issueOn->format('d/m/Y'),
                'due_date' => ($receipt ? $issueOn : ($direct === null ? $receivable->due_on : CarbonImmutable::parse($direct['dueOn'])))->format('d/m/Y'),
                'reference' => $direct === null ? "Contiu #{$receivableId}" : 'Contiu '.$direct['key'],
                'sequence_id' => (string) $sequence['id'],
                'currency_code' => 'EUR',
                'mb_reference' => '0',
                'client' => ['id' => $customerId],
                'items' => $items,
                ...($withholding === null ? [] : ['retention_basis_points' => $withholding['basisPoints']]),
                ...($exemptionReason === null ? [] : ['tax_exemption_reason' => $exemptionReason]),
                ...($eu === null ? [] : ['observations' => 'Data da prestação do serviço: '.CarbonImmutable::parse($euOperationOn)->format('d/m/Y').'.']),
            ],
            ...($documentType !== 'invoice' || count($lines) > 1 ? ['documentType' => $documentType, 'lines' => $lines] : []),
            ...($payment === null ? [] : ['payment' => $payment]),
            ...($withholding === null ? [] : ['withholding' => $withholding]),
        ];
    }

    /** @return array{id: int, paidOn: string, amount: int, allocationId?: int} */
    private function receiptPayment(Receivable $receivable, string $issueOn, ?int $fiscalDocumentId, ?array $withholding = null): array
    {
        $payments = $receivable->payments()->lockForUpdate()->get();
        $payment = $payments->first();
        $cash = $withholding['cash'] ?? $receivable->amount;

        if ($payments->count() !== 1 || $payment->is_split || $payment->isAdvance()
            || (int) $payment->receivable_id !== $receivable->id || filled($payment->invoice_number)
            || $payment->amount !== $cash || (int) $payment->pivot->amount !== $cash
            || $payment->paid_on->toDateString() !== $issueOn || substr((string) $payment->pivot->allocated_on, 0, 10) !== $issueOn
            || $payment->allocations()->where('receivable_id', '!=', $receivable->id)->exists()
            || $receivable->creditApplications()->effectiveAt($issueOn)->exists()
            || FiscalDocumentLink::query()->where('payment_id', $payment->id)
                ->when($fiscalDocumentId !== null, fn ($query) => $query->where('fiscal_document_id', '!=', $fiscalDocumentId))
                ->whereHas('fiscalDocument', fn ($query) => $query->where('status', FiscalDocumentStatus::Active))
                ->exists()) {
            throw ValidationException::withMessages(['payment' => $withholding === null
                ? __('The invoice receipt requires one payment in full recorded on the issue date, without a split, applied credit or linked fiscal document.')
                : __('An invoice receipt with withholding requires a single payment of the net amount of :amount on the issue date.', ['amount' => Money::format($cash, app()->getLocale())])]);
        }

        return [
            'id' => $payment->id, 'paidOn' => $issueOn, 'amount' => $payment->amount,
            ...($withholding === null ? [] : ['allocationId' => $payment->allocations()->where('receivable_id', $receivable->id)->sole()->id]),
        ];
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
            $current = $this->snapshot(
                $snapshot['receivableId'],
                $snapshot['customer']['id'],
                $snapshot['item']['id'],
                $snapshot['invoice']['items'][0]['quantity'],
                array_map(fn (array $line, array $item): array => [
                    'itemId' => $line['item']['id'], 'quantity' => $line['quantity'],
                    'discountBasisPoints' => $item['discount_basis_points'] ?? 0,
                ], array_slice($snapshot['lines'] ?? [], 1), array_slice($snapshot['invoice']['items'], 1)),
                $snapshot['documentType'] ?? 'invoice',
                isset($snapshot['euService']) ? null : ($snapshot['invoice']['tax_exemption_reason'] ?? null),
                forNewIssue: false,
                discountBasisPoints: $snapshot['invoice']['items'][0]['discount_basis_points'] ?? 0,
                direct: $snapshot['direct'] ?? null,
                euOperationOn: $snapshot['euOperationOn'] ?? null,
                withholdingBasisPoints: $snapshot['withholding']['basisPoints'] ?? 0,
            );

            return $snapshot === $current || $snapshot === $this->withLegacyInvoice($current);
        } catch (ValidationException) {
            return false;
        }
    }

    private function withLegacyInvoice(array $snapshot): array
    {
        $snapshot['invoice'] = $this->providerInvoice($snapshot['invoice']);

        return $snapshot;
    }

    private function providerInvoice(array $invoice): array
    {
        if (isset($invoice['retention_basis_points'])) {
            $rate = $invoice['retention_basis_points'];
            $invoice['retention'] = sprintf('%d.%02d', intdiv($rate, 100), $rate % 100);
            unset($invoice['retention_basis_points']);
        }

        $invoice['items'] = array_map(function (array $item): array {
            $providerItem = [];
            foreach ($item as $field => $value) {
                match ($field) {
                    'unit_price_cents' => $providerItem['unit_price'] = $value / 100,
                    'discount_basis_points' => $providerItem['discount'] = $value / 100,
                    default => $providerItem[$field] = $value,
                };
            }

            return $providerItem;
        }, $invoice['items']);

        return $invoice;
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
            throw ValidationException::withMessages(['confirmation' => __('The attempt belongs to another InvoiceXpress account. Restore the original connection to review it.')]);
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
            throw new InvoiceXpressException(__('InvoiceXpress returned incomplete data.'));
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
            if (! is_array($remoteCustomer)) {
                return false;
            }

            $customer = isset($snapshot['euService'])
                ? InvoiceXpressEuService::customer($remoteCustomer, $snapshot['customer']['id'], $snapshot['euService'])
                : InvoiceXpressIssueTerms::customer($remoteCustomer, $snapshot['customer']['id'], $snapshot['customer']['nif']);
            if ($customer !== $snapshot['customer']) {
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
        && (! isset($invoice['observations']) || ($document['observations'] ?? null) === $invoice['observations'])
        && InvoiceXpressIssueTerms::matchesAmounts($document, $totals, $snapshot['withholding'] ?? null)
        && InvoiceXpressIssueTerms::matchesDocumentDiscount($document, $totals['discount'] ?? 0)
        && in_array($document['tax_exemption'] ?? null, isset($invoice['tax_exemption_reason'])
            ? [$invoice['tax_exemption_reason']]
            : [null, '', 'M00'], true)
        && $this->matchesLines($items, $snapshot, $tax);
    }

    private function matchesLines(mixed $items, array $snapshot, array $tax): bool
    {
        $expectedItems = $this->providerInvoice($snapshot['invoice'])['items'];
        if (! is_array($items) || ! array_is_list($items) || count($items) !== count($expectedItems)) {
            return false;
        }

        foreach ($expectedItems as $index => $expected) {
            $details = $snapshot['lines'][$index] ?? $snapshot;
            if (! is_array($items[$index]) || ! InvoiceXpressIssueTerms::matchesLine(
                $items[$index], $expected, $details['item'], $tax, $details['totals'], $snapshot['withholding']['basisPoints'] ?? 0,
            )) {
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

        try {
            return DB::transaction(fn (): InvoiceXpressIssueAttempt => $this->storeFinal($attempt, $document, $number, $atcud));
        } catch (ValidationException) {
            return $this->block($attempt);
        }
    }

    private function storeFinal(InvoiceXpressIssueAttempt $attempt, array $document, string $number, string $atcud): InvoiceXpressIssueAttempt
    {
        $snapshot = $attempt->snapshot;
        $subdomain = $this->client->configuration()->subdomain;
        $payment = $snapshot['payment'] ?? null;
        $direct = isset($snapshot['direct']);
        $review = $this->documents->reviewInvoice($document, $subdomain);
        if (! in_array($review['state'], ['new', 'same'], true)) {
            return $this->block($attempt);
        }

        $receivable = $direct ? InvoiceXpressDirectSale::findReceivable($attempt) : Receivable::query()->lockForUpdate()->find($snapshot['receivableId']);
        if ($payment !== null) {
            $knownDocumentId = InvoiceXpressSnapshot::query()->where('subdomain', $subdomain)
                ->where('provider_id', $attempt->remote_draft_id)->latest('id')->value('fiscal_document_id');
            if ($receivable === null || $this->hasBillingDocument($receivable->id, $knownDocumentId)
                || $this->receiptPayment($receivable, $snapshot['issueOn'], $knownDocumentId, $snapshot['withholding'] ?? null) !== $payment) {
                return $this->block($attempt);
            }
        }

        $record = $this->documents->confirmInvoice($review['invoice'], expectedSubdomain: $subdomain);
        $local = $record->attributesToArray();
        $local['document_date'] = $record->document_date->toDateString();
        foreach ($review['after'] as $field => $value) {
            if (($local[$field] ?? null) !== $value) {
                throw ValidationException::withMessages(['confirmation' => __('The local fiscal document does not match the issued invoice.')]);
            }
        }

        $existingLink = $record->links()->lockForUpdate()->first();
        if (($direct && ($existingLink?->payment_id !== null || $existingLink?->expense_id !== null || $existingLink?->related_fiscal_document_id !== null))
            || (($direct || $receivable !== null) && $existingLink?->receivable_id !== null && (int) $existingLink->receivable_id !== $receivable?->id)
            || ($payment !== null && $existingLink?->payment_id !== null && (int) $existingLink->payment_id !== $payment['id'])) {
            throw ValidationException::withMessages(['confirmation' => __('The invoice is already linked to another record.')]);
        }

        if ($direct && $receivable === null) {
            $receivable = InvoiceXpressDirectSale::record($attempt);
        }

        if ($receivable !== null && ($existingLink?->receivable_id === null || ($payment !== null && $existingLink->payment_id === null))) {
            $record->links()->updateOrCreate([], [
                'receivable_id' => $receivable->id,
                ...($payment === null ? [] : ['payment_id' => $payment['id']]),
            ]);
        }

        if (isset($snapshot['euService'])) {
            InvoiceXpressEuService::record($record, $snapshot['euService']);
        }

        if (isset($payment['allocationId'], $snapshot['withholding'])) {
            WithholdingReview::record($record->id, $snapshot['withholding']['retained'], allocationId: $payment['allocationId']);
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
