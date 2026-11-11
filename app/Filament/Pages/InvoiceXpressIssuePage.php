<?php

namespace App\Filament\Pages;

use App\Enums\FiscalProfilePartyType;
use App\Enums\FiscalProfileViesStatus;
use App\Enums\PortugueseVatRegion;
use App\Exceptions\InvoiceXpressException;
use App\Filament\Pages\Concerns\HasInvoiceXpressPilotSettings;
use App\Models\Client;
use App\Models\FiscalDocumentDraft;
use App\Models\InvoiceXpressConfig;
use App\Models\InvoiceXpressIssueAttempt;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\Retainer;
use App\Support\CurrentAccount;
use App\Support\InvoiceXpressDraftDelivery;
use App\Support\InvoiceXpressEuService;
use App\Support\InvoiceXpressIssue;
use App\Support\InvoiceXpressIssueAdvance;
use App\Support\InvoiceXpressIssueRepository;
use App\Support\InvoiceXpressIssueSettings;
use App\Support\InvoiceXpressIssueTerms;
use App\Support\InvoiceXpressSandboxDrafts;
use App\Support\PortugueseVatSuggestion;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class InvoiceXpressIssuePage extends InvoiceXpressSectionPage
{
    use HasInvoiceXpressPilotSettings;

    private const array PreviewFields = [
        'billingSource', 'localClientId', 'dueOn', 'vatTreatment', 'operationOn', 'receivableId',
        'customerId', 'itemId', 'quantity', 'discount', 'documentType', 'additionalLines',
        'exemptionReason', 'withholdingRate', 'vatRegion', 'vatCategory',
    ];

    protected static ?string $slug = 'emitir-fatura-invoicexpress';

    protected string $view = 'filament.pages.invoice-xpress-issue';

    #[Locked]
    public int $accountId;

    #[Locked]
    public ?array $previewData = null;

    #[Locked]
    public ?int $draftId = null;

    #[Locked]
    public string $draftRequestKey;

    #[Locked]
    public string $directKey;

    public string $billingSource = 'receivable';

    public string $localClientId = '';

    public string $dueOn = '';

    public string $vatTreatment = 'domestic';

    public string $vatRegion = '';

    public string $vatCategory = '';

    public string $operationOn = '';

    public string $receivableId = '';

    public string $customerId = '';

    public string $itemId = '';

    public string $quantity = '1';

    public string $discount = '';

    public string $documentType = 'invoice';

    public string $exemptionReason = '';

    public string $withholdingRate = '';

    public array $additionalLines = [];

    public bool $accepted = false;

    public static function canAccess(): bool
    {
        return InvoiceXpressIssueRepository::ownerCanIssue()
            && (InvoiceXpressConfig::current() !== null || InvoiceXpressIssueAttempt::query()->exists());
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->accountId = CurrentAccount::id();
        $this->draftRequestKey = (string) Str::uuid();
        $this->directKey = (string) Str::uuid();
        $this->operationOn = today()->toDateString();

        $draftId = request()->query('draft');
        if ($draftId !== null) {
            $id = filter_var($draftId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            abort_if($id === false, 404);
            $this->draftId = $id;
            $draft = $this->savedDraft();
            if ($draft->state !== FiscalDocumentDraft::Local) {
                return;
            }

            $this->restoreDraftInputs($draft);
        } else {
            $type = request()->query('type');
            if (is_string($type) && isset(InvoiceXpressIssue::DocumentTypes[$type])) {
                $this->documentType = $type;
            }
        }

        $this->fillInvoiceXpressPilotSettings();
    }

    private function restoreDraftInputs(FiscalDocumentDraft $draft): void
    {
        $input = $draft->payload['inputs'] ?? [];
        $direct = $input['direct'] ?? null;
        $operationOn = $input['euOperationOn'] ?? null;
        $this->documentType = $draft->document_type;
        $this->billingSource = $direct === null ? 'receivable' : 'direct';
        $this->vatTreatment = $operationOn === null ? 'domestic' : 'euService';
        $this->operationOn = $operationOn ?? $this->operationOn;
        $this->directKey = $direct['key'] ?? $this->directKey;
        $this->localClientId = (string) ($direct['clientId'] ?? '');
        $this->dueOn = $direct['dueOn'] ?? '';
        $this->receivableId = $direct === null ? (string) ($input['receivableId'] ?? '') : '';
        $this->customerId = (string) ($input['customerId'] ?? '');
        $this->itemId = (string) ($input['itemId'] ?? '');
        $this->quantity = (string) ($input['quantity'] ?? 1);
        $this->discount = self::formatDiscount((int) ($input['discountBasisPoints'] ?? 0));
        $this->exemptionReason = $input['exemptionReason'] ?? '';
        $this->vatRegion = $input['domesticVat']['region'] ?? '';
        $this->vatCategory = $input['domesticVat']['category'] ?? '';
        $this->withholdingRate = (string) (($input['withholdingBasisPoints'] ?? 0) ?: '');
        $this->additionalLines = array_map(fn (array $line): array => [
            'itemId' => (string) $line['itemId'],
            'quantity' => (string) $line['quantity'],
            'discount' => self::formatDiscount((int) ($line['discountBasisPoints'] ?? 0)),
        ], $input['additionalLines'] ?? []);
    }

    private static function formatDiscount(int $basisPoints): string
    {
        return $basisPoints === 0 ? '' : sprintf('%d.%02d', intdiv($basisPoints, 100), $basisPoints % 100);
    }

    private function savedDraft(): FiscalDocumentDraft
    {
        return FiscalDocumentDraftsPage::draftQuery()
            ->whereIn('document_type', array_keys(InvoiceXpressIssue::DocumentTypes))
            ->findOrFail($this->draftId);
    }

    private function authorizeDraftEdit(): void
    {
        $this->authorizeAccount();
        abort_if($this->draftId !== null && $this->savedDraft()->state !== FiscalDocumentDraft::Local, 403);
    }

    protected function beforeInvoiceXpressCatalogLoad(): void
    {
        $this->reset('previewData', 'accepted');
    }

    protected function afterInvoiceXpressPilotSettingsSave(): void
    {
        $this->reset('previewData', 'accepted', 'itemId', 'discount', 'additionalLines');
    }

    public function updated(string $property): void
    {
        if ($property === 'billingSource' && $this->billingSource === 'direct') {
            $this->documentType = 'invoice';
        }
        if ($property === 'vatTreatment') {
            $this->reset('receivableId', 'localClientId', 'exemptionReason');
            if ($this->vatTreatment === 'euService') {
                $this->documentType = 'invoice';
                $this->reset('withholdingRate');
            }
        }

        if (in_array($property, ['billingSource', 'vatTreatment', 'receivableId', 'localClientId', 'exemptionReason'], true)) {
            $this->suggestVatRegion();
        }

        if ($property === 'vatRegion') {
            $this->selectReceivableVatCategory();
        }

        if ($property === 'documentType' && $this->documentType === 'simplified_invoice') {
            $this->reset('withholdingRate');
        }

        if (in_array(explode('.', $property, 2)[0], self::PreviewFields, true)) {
            $this->reset('previewData', 'accepted');
            $this->resetValidation();
        }
    }

    private function suggestVatRegion(): void
    {
        $this->reset('vatRegion', 'vatCategory');
        if (! $this->usesDomesticVat()) {
            return;
        }

        $receivable = $this->selectedReceivable();
        $this->vatRegion = $this->suggestedVatRegion($receivable)?->value ?? '';
        $this->selectReceivableVatCategory($receivable);
    }

    private function suggestedVatRegion(?Receivable $receivable): ?PortugueseVatRegion
    {
        $client = $this->billingSource === 'direct'
            ? Client::query()->find((int) $this->localClientId)
            : $receivable?->client;

        return $client === null ? null : PortugueseVatSuggestion::forClient($client);
    }

    private function selectedReceivable(): ?Receivable
    {
        return $this->billingSource === 'receivable' && $this->receivableId !== ''
            ? Receivable::query()->excludingCancelled()->with('billable.client')->find((int) $this->receivableId)
            : null;
    }

    private function selectReceivableVatCategory(?Receivable $receivable = null): void
    {
        $region = PortugueseVatRegion::tryFrom($this->vatRegion);
        $rate = InvoiceXpressIssueTerms::vatBasisPoints(($receivable ?? $this->selectedReceivable())?->billable?->vat_rate);
        $this->vatCategory = $rate === null ? '' : ($region?->categoryFor($rate) ?? '');
    }

    private function usesDomesticVat(): bool
    {
        return $this->vatTreatment === 'domestic' && $this->exemptionReason === '';
    }

    private function requiresDomesticVat(): bool
    {
        if (! $this->usesDomesticVat()) {
            return false;
        }

        if ($this->vatRegion !== '' || $this->vatCategory !== '') {
            return true;
        }

        if ($this->draftId !== null) {
            $inputs = $this->savedDraft()->payload['inputs'] ?? [];
            if (isset($inputs['domesticVat'])) {
                return true;
            }
            if ($this->billingSource === 'direct'
                ? (int) $this->localClientId === ($inputs['direct']['clientId'] ?? null)
                : (int) $this->receivableId === ($inputs['receivableId'] ?? null)) {
                return false;
            }
        }

        $receivable = $this->selectedReceivable();
        $rate = InvoiceXpressIssueTerms::vatBasisPoints($receivable?->billable?->vat_rate);

        return InvoiceXpressIssueSettings::regionalTaxNames() !== []
            || $this->suggestedVatRegion($receivable) !== null
            || ($rate !== null && $rate !== 0 && PortugueseVatRegion::Mainland->categoryFor($rate) === null);
    }

    public function preview(InvoiceXpressIssue $issue): void
    {
        $this->authorizeDraftEdit();
        $this->reset('previewData', 'accepted');
        $data = $this->documentInputs();

        try {
            $this->previewData = $issue->preview(...$data);
        } catch (InvoiceXpressException) {
            throw ValidationException::withMessages(['provider' => __('Unable to verify the details in InvoiceXpress.')]);
        }
    }

    public function confirm(InvoiceXpressIssue $issue): void
    {
        $this->authorizeDraftEdit();

        if (isset($this->previewData['advanceSettlement'])) {
            throw ValidationException::withMessages(['confirmation' => __(InvoiceXpressIssueAdvance::ProviderBlock)]);
        }

        if ($this->previewData === null || ! $this->accepted) {
            throw ValidationException::withMessages(['accepted' => __('Preview the details and confirm you reviewed the document.')]);
        }

        try {
            $attempt = $issue->confirm(...$this->documentInputs(), revision: $this->previewData['revision']);
        } catch (InvoiceXpressException) {
            throw ValidationException::withMessages(['provider' => __('Unable to confirm the details in InvoiceXpress. Check the recorded attempts.')]);
        }

        $this->resetCompletedDirectInvoice($attempt);
        $this->reset('previewData', 'accepted');
        $this->resetValidation();
    }

    public function saveDraft(): void
    {
        $this->authorizeDraftEdit();
        $this->resetValidation();
        if (! isset($this->previewData['revision'])) {
            throw ValidationException::withMessages(['confirmation' => __('Preview the details before saving the draft.')]);
        }

        try {
            $draft = app(InvoiceXpressDraftDelivery::class)->saveInvoice(
                $this->documentInputs(),
                $this->previewData['revision'],
                $this->draftId,
                $this->draftRequestKey,
            );
        } catch (InvoiceXpressException) {
            throw ValidationException::withMessages(['provider' => __('Unable to verify the details in InvoiceXpress.')]);
        }

        $this->draftId = $draft->id;
        $this->reset('previewData', 'accepted');
    }

    public function createDraft(): void
    {
        $this->saveDraft();
    }

    /** @return array{receivableId: int, customerId: int, itemId: int, quantity: int, additionalLines: array, documentType: string, exemptionReason: ?string, discountBasisPoints: int, direct: ?array, euOperationOn: ?string, withholdingBasisPoints: int, domesticVat?: array{region: string, category: string}} */
    private function documentInputs(): array
    {
        $requiresDomesticVat = $this->requiresDomesticVat();
        $data = $this->validate([
            'billingSource' => ['required', 'in:receivable,direct'],
            'vatTreatment' => ['required', 'in:domestic,euService'],
            'receivableId' => ['exclude_unless:billingSource,receivable', 'required', 'integer', 'min:1'],
            'localClientId' => ['exclude_unless:billingSource,direct', 'required', 'integer', 'min:1'],
            'dueOn' => ['exclude_unless:billingSource,direct', 'required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'operationOn' => ['exclude_unless:vatTreatment,euService', 'required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'customerId' => ['required', 'regex:/\A[1-9][0-9]{0,17}\z/'],
            'itemId' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'discount' => ['nullable', 'regex:/\A[0-9]{1,3}(?:[.,][0-9]{1,2})?\z/'],
            'documentType' => ['required', Rule::in(array_keys(InvoiceXpressIssue::DocumentTypes))],
            'exemptionReason' => ['nullable', 'in:M10'],
            'vatRegion' => [Rule::excludeIf(! $requiresDomesticVat), 'required', Rule::enum(PortugueseVatRegion::class)],
            'vatCategory' => [Rule::excludeIf(! $requiresDomesticVat), 'required', 'in:reduced,intermediate,standard'],
            'withholdingRate' => ['nullable', Rule::in(array_keys(InvoiceXpressIssueTerms::WithholdingRates))],
            'additionalLines' => ['array', 'list', 'max:'.(InvoiceXpressIssue::MaxLines - 1)],
            'additionalLines.*' => ['required', 'array:itemId,quantity,discount'],
            'additionalLines.*.itemId' => ['required', 'integer', 'min:1'],
            'additionalLines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'additionalLines.*.discount' => ['nullable', 'regex:/\A[0-9]{1,3}(?:[.,][0-9]{1,2})?\z/'],
        ], [
            'receivableId.*' => __('Choose a receivable.'),
            'localClientId.*' => __('Choose a client from Contiu.'),
            'dueOn.*' => __('Enter a due date on or after today.'),
            'operationOn.*' => __('Enter the date you provided the service.'),
            'customerId.*' => __('Choose an existing customer in InvoiceXpress.'),
            'itemId.*' => __('Choose an approved item.'),
            'quantity.*' => __('Enter a whole quantity from 1 to 1000.'),
            'documentType.*' => __('Choose a supported document type.'),
            'vatRegion.*' => __('Choose a VAT region.'),
            'vatCategory.*' => __('Choose a VAT rate category.'),
            'additionalLines.*.itemId.*' => __('Choose an approved item.'),
            'additionalLines.*.quantity.*' => __('Enter a whole quantity from 1 to 1000.'),
        ]);

        return [
            'receivableId' => (int) ($data['receivableId'] ?? 0),
            'customerId' => (int) $data['customerId'],
            'itemId' => (int) $data['itemId'],
            'quantity' => (int) $data['quantity'],
            'additionalLines' => array_map(fn (array $line): array => [
                'itemId' => $line['itemId'], 'quantity' => $line['quantity'],
                'discountBasisPoints' => InvoiceXpressIssueTerms::hundredths($line['discount'] ?? null) ?? 0,
            ], $data['additionalLines']),
            'documentType' => $data['documentType'],
            'exemptionReason' => filled($data['exemptionReason'] ?? null) ? $data['exemptionReason'] : null,
            'discountBasisPoints' => InvoiceXpressIssueTerms::hundredths($data['discount'] ?? null) ?? 0,
            'direct' => $data['billingSource'] === 'direct' ? ['key' => $this->directKey, 'clientId' => (int) $data['localClientId'], 'dueOn' => $data['dueOn']] : null,
            'euOperationOn' => $data['operationOn'] ?? null,
            'withholdingBasisPoints' => (int) ($data['withholdingRate'] ?? 0),
            ...($requiresDomesticVat ? ['domesticVat' => ['region' => $data['vatRegion'], 'category' => $data['vatCategory']]] : []),
        ];
    }

    public function recover(int $attemptId, InvoiceXpressIssue $issue): void
    {
        $this->authorizeDraftEdit();
        $this->resetValidation();
        try {
            $this->resetCompletedDirectInvoice($issue->recover($attemptId));
        } catch (InvoiceXpressException) {
            throw ValidationException::withMessages(['confirmation' => __('Restore the original InvoiceXpress connection to verify this attempt.')]);
        }
    }

    private function resetCompletedDirectInvoice(InvoiceXpressIssueAttempt $attempt): void
    {
        if ($attempt->state === InvoiceXpressIssueAttempt::Finalized && ($attempt->snapshot['direct']['key'] ?? null) === $this->directKey) {
            $this->directKey = (string) Str::uuid();
            $this->reset('previewData', 'accepted', 'localClientId', 'customerId', 'dueOn', 'itemId', 'quantity', 'discount', 'withholdingRate', 'additionalLines', 'vatRegion', 'vatCategory');
        }
    }

    protected function getViewData(): array
    {
        $this->authorizeAccount();
        $savedDraft = $this->draftId === null ? null : $this->savedDraft();
        if ($savedDraft !== null && $savedDraft->state !== FiscalDocumentDraft::Local) {
            return ['savedDraft' => $savedDraft, 'readOnlyDraft' => true];
        }

        $options = InvoiceXpressIssue::options();
        $draftOnly = $options['draftOnly'] ?? false;
        $config = InvoiceXpressConfig::current();
        $catalog = $config !== null && $config->subdomain === ($this->catalog['account'] ?? null) ? $this->catalog : null;
        $open = InvoiceXpressIssueAttempt::query()->where('state', '!=', InvoiceXpressIssueAttempt::Finalized)->latest('id')->get();
        $fiscalClients = function (Builder $query) use ($draftOnly): Builder {
            if ($this->vatTreatment === 'euService') {
                $countries = array_map(fn (string $prefix): string => $prefix === 'EL' ? 'GR' : $prefix, InvoiceXpressEuService::vatPrefixes());

                return $query->whereIn('fiscal_country_code', $countries)
                    ->where('fiscal_party_type', FiscalProfilePartyType::Business)
                    ->where('vat_validation_status', FiscalProfileViesStatus::Valid);
            }

            return $draftOnly ? $query : $query->where('fiscal_country_code', 'PT');
        };

        return [
            'savedDraft' => $savedDraft,
            'readOnlyDraft' => false,
            'options' => $options,
            'draftOnly' => $draftOnly,
            'documentTypes' => array_map(fn (string $label): string => __($label), $draftOnly ? InvoiceXpressSandboxDrafts::DocumentTypes : InvoiceXpressIssue::DocumentTypes),
            'catalogChoices' => $catalog,
            'reviewedInputs' => $this->previewData === null ? [] : $this->only(self::PreviewFields),
            'hasConnection' => $config !== null,
            'localClients' => $options === null || $this->billingSource !== 'direct' ? collect() : $fiscalClients(Client::query())
                ->when(! $draftOnly || $this->vatTreatment === 'euService',
                    fn (Builder $query) => $query->whereNotNull('vat_number')->whereNotNull('fiscal_party_type'))
                ->orderBy('name')->pluck('name', 'id'),
            'receivables' => $options === null || $this->billingSource !== 'receivable' ? collect() : Receivable::query()->excludingCancelled()
                ->when($this->documentType === 'invoice_receipt' && ! $draftOnly,
                    fn (Builder $query) => $query->whereHas('payments', fn (Builder $payments) => $payments
                        ->whereDate('paid_on', today())
                        ->when($this->withholdingRate === '', fn (Builder $payment) => $payment->whereColumn('payments.amount', 'receivables.amount'))))
                ->when($this->documentType !== 'invoice_receipt', fn (Builder $query) => $query->whereDate('due_on', '>=', today()))
                ->whereHasMorph('billable', [Project::class, Retainer::class], fn (Builder $query) => $query
                    ->whereHas('client', $fiscalClients))
                ->with('billable.client')
                ->when($this->receivableId !== '', fn (Builder $query) => $query
                    ->orderByRaw('case when id = ? then 0 else 1 end', [(int) $this->receivableId]))
                ->orderBy('due_on')
                ->orderBy('id')
                ->limit(100)
                ->get(),
            'attempts' => $open->concat(InvoiceXpressIssueAttempt::query()
                ->where('state', InvoiceXpressIssueAttempt::Finalized)->latest('id')->limit(25)->get()),
        ];
    }

    private function authorizeAccount(): void
    {
        abort_unless($this->accountId === CurrentAccount::get()?->getKey() && static::canAccess(), 403);
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return __('Prepare invoice');
    }

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('Prepare invoice');
    }
}
