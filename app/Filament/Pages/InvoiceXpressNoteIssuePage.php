<?php

namespace App\Filament\Pages;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Exceptions\InvoiceXpressException;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentDraft;
use App\Models\InvoiceXpressConfig;
use App\Models\InvoiceXpressNoteAttempt;
use App\Support\CurrentAccount;
use App\Support\InvoiceXpressDraftDelivery;
use App\Support\InvoiceXpressIssue;
use App\Support\InvoiceXpressIssueRepository;
use App\Support\InvoiceXpressNoteIssue;
use App\Support\InvoiceXpressNoteTerms;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class InvoiceXpressNoteIssuePage extends InvoiceXpressSectionPage
{
    private const array PreviewFields = ['originalId', 'kind', 'line', 'itemId', 'quantity', 'reason'];

    protected static ?string $slug = 'emitir-nota-invoicexpress';

    protected string $view = 'filament.pages.invoice-xpress-note-issue';

    #[Locked]
    public int $accountId;

    #[Locked]
    public ?array $originalData = null;

    #[Locked]
    public ?array $previewData = null;

    #[Locked]
    public ?int $draftId = null;

    #[Locked]
    public string $draftRequestKey;

    public string $originalId = '';

    public string $kind = 'credit_note';

    public string $line = '';

    public string $itemId = '';

    public string $quantity = '1';

    public string $reason = '';

    public bool $accepted = false;

    public static function canAccess(): bool
    {
        return InvoiceXpressIssueRepository::ownerCanIssue()
            && (InvoiceXpressConfig::current() !== null || InvoiceXpressNoteAttempt::query()->exists());
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->accountId = CurrentAccount::id();
        $this->draftRequestKey = (string) Str::uuid();

        $draftId = request()->query('draft');
        if ($draftId !== null) {
            $id = filter_var($draftId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            abort_if($id === false, 404);
            $this->draftId = $id;
            $draft = $this->savedDraft();
            if ($draft->state === FiscalDocumentDraft::Local) {
                $input = $draft->payload['inputs'] ?? [];
                $this->kind = $draft->document_type;
                $this->originalId = (string) ($input['originalId'] ?? '');
                $this->line = (string) ($input['line'] ?? '');
                $this->itemId = (string) ($input['itemId'] ?? '');
                $this->quantity = (string) ($input['quantity'] ?? 1);
                $this->reason = $input['reason'] ?? '';
            }

            return;
        }

        $kind = request()->query('type');
        if (in_array($kind, array_keys(InvoiceXpressNoteTerms::Types), true)) {
            $this->kind = $kind;
        }

        $originalId = request()->query('originalId');
        if ($originalId !== null) {
            $id = filter_var($originalId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $subdomain = InvoiceXpressConfig::current()?->subdomain;
            abort_if($id === false || $subdomain === null, 404);
            $original = $this->originalsQuery($subdomain)->findOrFail($id);
            $this->originalId = (string) $original->id;
            if ($original->type === FiscalDocumentType::InvoiceReceipt) {
                $this->kind = 'credit_note';
            }
        }
    }

    private function savedDraft(): FiscalDocumentDraft
    {
        return FiscalDocumentDraftsPage::draftQuery()
            ->whereIn('document_type', array_keys(InvoiceXpressNoteTerms::Types))
            ->findOrFail($this->draftId);
    }

    private function authorizeDraftEdit(): void
    {
        $this->authorizeAccount();
        abort_if($this->draftId !== null && $this->savedDraft()->state !== FiscalDocumentDraft::Local, 403);
    }

    public function updated(string $property): void
    {
        if ($property === 'originalId') {
            $this->reset('originalData', 'line');
        }

        if (in_array($property, self::PreviewFields, true)) {
            $this->reset('previewData', 'accepted');
            $this->resetValidation();
        }
    }

    public function loadOriginal(InvoiceXpressNoteIssue $issue): void
    {
        $this->authorizeDraftEdit();
        $this->reset('originalData', 'previewData', 'accepted');
        $this->resetValidation();
        $this->validate(['originalId' => ['required', 'integer', 'min:1']], [
            'originalId.*' => __('Choose the original invoice.'),
        ]);

        try {
            $original = $issue->original((int) $this->originalId);
            $this->originalData = [
                'number' => $original['number'],
                'allowsDebit' => ($original['providerType'] ?? null) !== 'InvoiceReceipt',
                'lines' => array_map(fn (array $line): array => [
                    'name' => $line['article']['name'],
                    'quantity' => $line['quantity'],
                    'total' => $line['totals']['total'],
                ], $original['lines']),
            ];
            if (! array_key_exists((int) $this->line - 1, $this->originalData['lines'])) {
                $this->reset('line');
            }
            if (! $this->originalData['allowsDebit']) {
                $this->kind = 'credit_note';
            }
        } catch (InvoiceXpressException $exception) {
            throw ValidationException::withMessages(['provider' => $exception->getMessage()]);
        }
    }

    public function preview(InvoiceXpressNoteIssue $issue): void
    {
        $this->authorizeDraftEdit();
        $this->reset('previewData', 'accepted');
        $this->resetValidation();

        try {
            $preview = $issue->preview($this->input());
            $line = $preview['original']['lines'][$preview['input']['line'] - 1];
            $this->previewData = [
                'revision' => $preview['revision'],
                'draftOnly' => $preview['draftOnly'] ?? false,
                'number' => $preview['original']['number'],
                'customer' => $preview['original']['customer'],
                'line' => $line['article']['name'],
                'exemptionReason' => $line['exemptionReason'] ?? null,
                'withholding' => $preview['withholding'] ?? null,
                'input' => $preview['input'],
                'remaining' => $preview['remaining'],
                'totals' => $preview['totals'],
                'sequence' => $preview['sequence']['name'],
                'date' => $preview['payload']['date'],
            ];
        } catch (InvoiceXpressException $exception) {
            throw ValidationException::withMessages(['provider' => $exception->getMessage()]);
        }
    }

    public function confirm(InvoiceXpressNoteIssue $issue): void
    {
        $this->authorizeDraftEdit();
        $this->resetValidation();
        $this->validate(['accepted' => 'accepted'], ['accepted.accepted' => __('Confirm that you authorize issuing the note.')]);

        if ($this->previewData === null) {
            throw ValidationException::withMessages(['confirmation' => __('Preview the details before issuing the note.')]);
        }

        try {
            $issue->confirm($this->input(), $this->previewData['revision']);
            $this->reset('previewData', 'accepted');
        } catch (InvoiceXpressException $exception) {
            throw ValidationException::withMessages(['provider' => $exception->getMessage()]);
        }
    }

    public function saveDraft(): void
    {
        $this->authorizeDraftEdit();
        $this->resetValidation();
        if (! isset($this->previewData['revision'])) {
            throw ValidationException::withMessages(['confirmation' => __('Preview the details before saving the draft.')]);
        }

        try {
            $draft = app(InvoiceXpressDraftDelivery::class)->saveNote(
                $this->input(), $this->previewData['revision'], $this->draftId, $this->draftRequestKey,
            );
        } catch (InvoiceXpressException $exception) {
            throw ValidationException::withMessages(['provider' => $exception->getMessage()]);
        }

        $this->draftId = $draft->id;
        $this->reset('previewData', 'accepted');
    }

    public function createDraft(): void
    {
        $this->saveDraft();
    }

    public function recover(int $attemptId, InvoiceXpressNoteIssue $issue): void
    {
        $this->authorizeDraftEdit();
        $this->resetValidation();

        try {
            $issue->recover($attemptId);
        } catch (InvoiceXpressException $exception) {
            throw ValidationException::withMessages(['provider' => $exception->getMessage()]);
        }
    }

    protected function getViewData(): array
    {
        $this->authorizeAccount();
        $savedDraft = $this->draftId === null ? null : $this->savedDraft();
        if ($savedDraft !== null && $savedDraft->state !== FiscalDocumentDraft::Local) {
            return ['savedDraft' => $savedDraft, 'readOnlyDraft' => true];
        }

        $config = InvoiceXpressConfig::current();
        $options = InvoiceXpressIssue::options();

        return [
            'savedDraft' => $savedDraft,
            'readOnlyDraft' => false,
            'itemIds' => $options['itemIds'] ?? [],
            'hasConnection' => $config !== null,
            'draftOnly' => $options['draftOnly'] ?? false,
            'reviewedInputs' => $this->previewData === null ? [] : $this->input(),
            'originals' => $options === null || $config === null ? collect() : $this->originalsQuery($config->subdomain)
                ->when($this->originalId !== '', fn (Builder $query) => $query
                    ->orderByRaw('case when id = ? then 0 else 1 end', [(int) $this->originalId]))
                ->latest('document_date')->latest('id')
                ->limit(100)->get(),
            'attempts' => InvoiceXpressNoteAttempt::query()->where('state', '!=', InvoiceXpressNoteAttempt::Finalized)
                ->latest('id')->get()->concat(InvoiceXpressNoteAttempt::query()
                ->where('state', InvoiceXpressNoteAttempt::Finalized)->latest('id')->limit(25)->get()),
        ];
    }

    /** @return Builder<FiscalDocument> */
    private function originalsQuery(string $subdomain): Builder
    {
        return FiscalDocument::query()
            ->where('status', FiscalDocumentStatus::Active)
            ->where('direction', FiscalDocumentDirection::Issued)
            ->where('currency', 'EUR')
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $invoices) => $invoices->where('type', FiscalDocumentType::Invoice)
                    ->whereHas('invoiceXpressSnapshot', fn (Builder $snapshot) => $snapshot->where('subdomain', $subdomain)->where('source->provider_type', 'Invoice')))
                ->orWhere(fn (Builder $receipts) => $receipts->where('type', FiscalDocumentType::InvoiceReceipt)
                    ->whereHas('invoiceXpressSnapshot', fn (Builder $snapshot) => $snapshot->where('subdomain', $subdomain)->where('source->provider_type', 'InvoiceReceipt'))));
    }

    private function input(): array
    {
        return $this->only(self::PreviewFields);
    }

    private function authorizeAccount(): void
    {
        abort_unless($this->accountId === CurrentAccount::get()?->getKey() && static::canAccess(), 403);
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return __('Prepare credit or debit note');
    }

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('Prepare credit or debit note');
    }
}
