<?php

namespace App\Filament\Pages;

use App\Exceptions\InvoiceXpressException;
use App\Filament\Pages\Concerns\HasInvoiceXpressPilotSettings;
use App\Models\InvoiceXpressConfig;
use App\Models\InvoiceXpressIssueAttempt;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\Retainer;
use App\Support\CurrentAccount;
use App\Support\InvoiceXpressIssue;
use App\Support\InvoiceXpressIssueAdvance;
use App\Support\InvoiceXpressIssueRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class InvoiceXpressIssuePage extends InvoiceXpressSectionPage
{
    use HasInvoiceXpressPilotSettings;

    protected static ?string $title = 'Emitir fatura no InvoiceXpress';

    protected static ?string $slug = 'emitir-fatura-invoicexpress';

    protected string $view = 'filament.pages.invoice-xpress-issue';

    #[Locked]
    public int $accountId;

    #[Locked]
    public ?array $previewData = null;

    public string $receivableId = '';

    public string $customerId = '';

    public string $itemId = '';

    public string $quantity = '1';

    public string $documentType = 'invoice';

    public string $exemptionReason = '';

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
        $this->fillInvoiceXpressPilotSettings();
    }

    public function getSubheading(): ?string
    {
        return 'A fatura é emitida no InvoiceXpress e fica ligada à cobrança.';
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
            ['label' => 'Dados do documento', 'state' => $previewed ? 'Verificados no InvoiceXpress' : 'Por preencher', 'done' => $previewed],
            ['label' => 'Confirmar emissão', 'state' => match (true) {
                ! $previewed => 'Depois da pré-visualização',
                $this->accepted => 'Pronta a emitir',
                default => 'Revê e confirma',
            }, 'done' => $previewed && $this->accepted],
            ['label' => 'Emitida', 'state' => match ($latest?->state) {
                null => 'Ainda sem tentativas',
                InvoiceXpressIssueAttempt::Finalized => 'Última: '.$latest->document_reference,
                InvoiceXpressIssueAttempt::Blocked => 'Última tentativa por rever',
                default => 'Última tentativa por confirmar',
            }, 'done' => $latest?->state === InvoiceXpressIssueAttempt::Finalized],
        ];
    }

    protected function beforeInvoiceXpressCatalogLoad(): void
    {
        $this->reset('previewData', 'accepted');
    }

    protected function afterInvoiceXpressPilotSettingsSave(): void
    {
        $this->reset('previewData', 'accepted', 'itemId', 'additionalLines');
    }

    public function updated(string $property): void
    {
        if (in_array(explode('.', $property, 2)[0], ['receivableId', 'customerId', 'itemId', 'quantity', 'documentType', 'additionalLines', 'exemptionReason'], true)) {
            $this->reset('previewData', 'accepted');
            $this->resetValidation();
        }
    }

    public function addLine(): void
    {
        $this->authorizeAccount();

        if (count($this->additionalLines) < InvoiceXpressIssue::MaxLines - 1) {
            $this->additionalLines[] = ['itemId' => '', 'quantity' => '1'];
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
            throw ValidationException::withMessages(['provider' => 'Não foi possível verificar os dados no InvoiceXpress.']);
        }
    }

    public function confirm(InvoiceXpressIssue $issue): void
    {
        $this->authorizeAccount();

        if (isset($this->previewData['advanceSettlement'])) {
            throw ValidationException::withMessages(['confirmation' => InvoiceXpressIssueAdvance::ProviderBlock]);
        }

        if ($this->previewData === null || ! $this->accepted) {
            throw ValidationException::withMessages(['accepted' => 'Pré-visualiza os dados e confirma que reveste o documento.']);
        }

        try {
            $issue->confirm(...$this->documentInputs(), revision: $this->previewData['revision']);
        } catch (InvoiceXpressException) {
            throw ValidationException::withMessages(['provider' => 'Não foi possível confirmar os dados no InvoiceXpress. Consulta as tentativas registadas.']);
        }

        $this->reset('previewData', 'accepted');
        $this->resetValidation();
    }

    /** @return array{receivableId: int, customerId: int, itemId: int, quantity: int, additionalLines: array, documentType: string} */
    private function documentInputs(): array
    {
        $data = $this->validate([
            'receivableId' => ['required', 'integer', 'min:1'],
            'customerId' => ['required', 'regex:/\A[1-9][0-9]{0,17}\z/'],
            'itemId' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'documentType' => ['required', Rule::in(array_keys(InvoiceXpressIssue::DocumentTypes))],
            'exemptionReason' => ['nullable', 'in:M10'],
            'additionalLines' => ['array', 'list', 'max:'.(InvoiceXpressIssue::MaxLines - 1)],
            'additionalLines.*' => ['required', 'array:itemId,quantity'],
            'additionalLines.*.itemId' => ['required', 'integer', 'min:1'],
            'additionalLines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
        ], [
            'receivableId.*' => 'Escolhe um valor a receber.',
            'customerId.*' => 'Escolhe um cliente existente no InvoiceXpress.',
            'itemId.*' => 'Escolhe um artigo aprovado.',
            'quantity.*' => 'Indica uma quantidade inteira entre 1 e 1000.',
            'documentType.*' => 'Escolhe um tipo de documento suportado.',
            'additionalLines.*.itemId.*' => 'Escolhe um artigo aprovado.',
            'additionalLines.*.quantity.*' => 'Indica uma quantidade inteira entre 1 e 1000.',
        ]);

        return [
            'receivableId' => (int) $data['receivableId'],
            'customerId' => (int) $data['customerId'],
            'itemId' => (int) $data['itemId'],
            'quantity' => (int) $data['quantity'],
            'additionalLines' => $data['additionalLines'],
            'documentType' => $data['documentType'],
            'exemptionReason' => filled($data['exemptionReason'] ?? null) ? $data['exemptionReason'] : null,
        ];
    }

    public function recover(int $attemptId, InvoiceXpressIssue $issue): void
    {
        $this->authorizeAccount();
        $this->resetValidation();
        try {
            $issue->recover($attemptId);
        } catch (InvoiceXpressException) {
            throw ValidationException::withMessages(['confirmation' => 'Repõe a ligação original ao InvoiceXpress para verificar esta tentativa.']);
        }
    }

    protected function getViewData(): array
    {
        $this->authorizeAccount();
        $options = InvoiceXpressIssue::options();
        $config = InvoiceXpressConfig::current();
        $catalog = $config !== null && $config->subdomain === ($this->catalog['account'] ?? null) ? $this->catalog : null;
        $open = InvoiceXpressIssueAttempt::query()->where('state', '!=', InvoiceXpressIssueAttempt::Finalized)->latest('id')->get();

        return [
            'options' => $options,
            'catalogChoices' => $catalog,
            'canWriteDocuments' => InvoiceXpressIssue::canWriteDocuments(),
            'hasConnection' => $config !== null,
            'receivables' => $options === null ? collect() : Receivable::query()->excludingCancelled()
                ->when($this->documentType === 'invoice_receipt',
                    fn (Builder $query) => $query->whereHas('payments', fn (Builder $payments) => $payments
                        ->whereDate('paid_on', today())->whereColumn('payments.amount', 'receivables.amount')),
                    fn (Builder $query) => $query->whereDate('due_on', '>=', today()))
                ->whereHasMorph('billable', [Project::class, Retainer::class], fn (Builder $query) => $query
                    ->whereHas('client', fn (Builder $clients) => $clients->where('fiscal_country_code', 'PT')))
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
}
