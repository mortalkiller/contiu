<?php

namespace App\Filament\Pages;

use App\Enums\FiscalProfilePartyType;
use App\Enums\FiscalProfileViesStatus;
use App\Exceptions\InvoiceXpressException;
use App\Filament\Pages\Concerns\HasInvoiceXpressPilotSettings;
use App\Models\Client;
use App\Models\InvoiceXpressConfig;
use App\Models\InvoiceXpressIssueAttempt;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\Retainer;
use App\Support\CurrentAccount;
use App\Support\InvoiceXpressEuService;
use App\Support\InvoiceXpressIssue;
use App\Support\InvoiceXpressIssueAdvance;
use App\Support\InvoiceXpressIssueRepository;
use App\Support\InvoiceXpressIssueTerms;
use App\Support\InvoiceXpressSandboxDrafts;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class InvoiceXpressIssuePage extends InvoiceXpressSectionPage
{
    use HasInvoiceXpressPilotSettings;

    protected static ?string $slug = 'emitir-fatura-invoicexpress';

    protected string $view = 'filament.pages.invoice-xpress-issue';

    #[Locked]
    public int $accountId;

    #[Locked]
    public ?array $previewData = null;

    #[Locked]
    public ?int $createdDraftId = null;

    #[Locked]
    public string $directKey;

    public string $billingSource = 'receivable';

    public string $localClientId = '';

    public string $dueOn = '';

    public string $vatTreatment = 'domestic';

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
        $this->directKey = (string) Str::uuid();
        $this->operationOn = today()->toDateString();
        $this->fillInvoiceXpressPilotSettings();
    }

    public function getSubheading(): ?string
    {
        return (InvoiceXpressIssue::options()['draftOnly'] ?? false)
            ? __('Prepare the invoice and send the draft to InvoiceXpress.')
            : __('The invoice is issued in InvoiceXpress and linked to the charge.');
    }

    /**
     * Where the document stands: data filled in, ready to confirm, and what the last attempt
     * came to. The third step reads the most recent attempt, not this visit alone.
     *
     * @param  Collection<int, InvoiceXpressIssueAttempt>  $attempts
     * @return list<array{label: string, state: string, done: bool}>
     */
    private function steps(Collection $attempts): array
    {
        $previewed = $this->previewData !== null;
        $latest = $attempts->sortByDesc('id')->first();

        return [
            ['label' => __('Document details'), 'state' => $previewed ? __('Verified in InvoiceXpress') : __('Incomplete'), 'done' => $previewed],
            ['label' => __('Confirm issuance'), 'state' => match (true) {
                ! $previewed => __('After preview'),
                $this->accepted => __('Ready to issue'),
                default => __('Review and confirm'),
            }, 'done' => $previewed && $this->accepted],
            ['label' => __('Invoice issued'), 'state' => match ($latest?->state) {
                null => __('No attempts yet'),
                InvoiceXpressIssueAttempt::Finalized => __('Last: :reference', ['reference' => $latest->document_reference]),
                InvoiceXpressIssueAttempt::Blocked => __('Last attempt needs review'),
                default => __('Last attempt needs confirmation'),
            }, 'done' => $latest?->state === InvoiceXpressIssueAttempt::Finalized],
        ];
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

        if ($property === 'documentType' && $this->documentType === 'simplified_invoice') {
            $this->reset('withholdingRate');
        }

        if (in_array(explode('.', $property, 2)[0], ['billingSource', 'localClientId', 'dueOn', 'vatTreatment', 'operationOn', 'receivableId', 'customerId', 'itemId', 'quantity', 'discount', 'documentType', 'additionalLines', 'exemptionReason', 'withholdingRate'], true)) {
            $this->reset('previewData', 'accepted');
            $this->resetValidation();
        }
    }

    public function addLine(): void
    {
        $this->authorizeAccount();

        if (count($this->additionalLines) < InvoiceXpressIssue::MaxLines - 1) {
            $this->additionalLines[] = ['itemId' => '', 'quantity' => '1', 'discount' => ''];
            $this->updated('additionalLines');
        }
    }

    public function removeLine(int $index): void
    {
        $this->authorizeAccount();
        unset($this->additionalLines[$index]);
        $this->additionalLines = array_values($this->additionalLines);
        $this->updated('additionalLines');
    }

    public function preview(InvoiceXpressIssue $issue): void
    {
        $this->authorizeAccount();
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
        $this->authorizeAccount();

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

    public function createDraft(InvoiceXpressIssue $issue, InvoiceXpressSandboxDrafts $drafts): void
    {
        $this->authorizeAccount();
        $this->resetValidation();
        if (! isset($this->previewData['revision']) || ! $this->accepted) {
            throw ValidationException::withMessages(['accepted' => __('Preview the details and confirm you reviewed the draft.')]);
        }

        try {
            $this->createdDraftId = $issue->createDraft($this->documentInputs(), $this->previewData['revision'], $drafts);
        } catch (InvoiceXpressException $exception) {
            throw ValidationException::withMessages(['confirmation' => $exception->getMessage()]);
        }

        $this->reset('previewData', 'accepted');
    }

    /** @return array{receivableId: int, customerId: int, itemId: int, quantity: int, additionalLines: array, documentType: string, exemptionReason: ?string, discountBasisPoints: int, direct: ?array, euOperationOn: ?string, withholdingBasisPoints: int} */
    private function documentInputs(): array
    {
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
        ];
    }

    public function recover(int $attemptId, InvoiceXpressIssue $issue): void
    {
        $this->authorizeAccount();
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
            $this->reset('previewData', 'accepted', 'localClientId', 'customerId', 'dueOn', 'itemId', 'quantity', 'discount', 'withholdingRate', 'additionalLines');
        }
    }

    protected function getViewData(): array
    {
        $this->authorizeAccount();
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
            'options' => $options,
            'draftOnly' => $draftOnly,
            'documentTypes' => array_map(fn (string $label): string => __($label), $draftOnly ? InvoiceXpressSandboxDrafts::DocumentTypes : InvoiceXpressIssue::DocumentTypes),
            'catalogChoices' => $catalog,
            'canWriteDocuments' => InvoiceXpressIssue::canWriteDocuments(),
            'canWriteWithholding' => InvoiceXpressIssue::canWriteWithholding(),
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
                ->orderBy('due_on')
                ->orderBy('id')
                ->limit(100)
                ->get(),
            'attempts' => $attempts = $open->concat(InvoiceXpressIssueAttempt::query()
                ->where('state', InvoiceXpressIssueAttempt::Finalized)->latest('id')->limit(25)->get()),
            'steps' => $this->steps($attempts),
        ];
    }

    private function authorizeAccount(): void
    {
        abort_unless(static::canAccess() && $this->accountId === CurrentAccount::id(), 403);
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return __('Invoices in InvoiceXpress');
    }

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('Invoices in InvoiceXpress');
    }
}
