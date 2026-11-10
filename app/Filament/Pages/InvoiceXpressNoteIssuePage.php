<?php

namespace App\Filament\Pages;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Exceptions\InvoiceXpressException;
use App\Models\FiscalDocument;
use App\Models\InvoiceXpressConfig;
use App\Models\InvoiceXpressNoteAttempt;
use App\Support\CurrentAccount;
use App\Support\InvoiceXpressIssue;
use App\Support\InvoiceXpressIssueRepository;
use App\Support\InvoiceXpressNoteIssue;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class InvoiceXpressNoteIssuePage extends InvoiceXpressSectionPage
{
    protected static ?string $title = 'Emitir nota de crédito ou débito';

    protected static ?string $slug = 'emitir-nota-invoicexpress';

    protected string $view = 'filament.pages.invoice-xpress-note-issue';

    #[Locked]
    public int $accountId;

    #[Locked]
    public ?array $originalData = null;

    #[Locked]
    public ?array $previewData = null;

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
    }

    public function updated(string $property): void
    {
        if ($property === 'originalId') {
            $this->reset('originalData', 'line');
        }

        if (in_array($property, ['originalId', 'kind', 'line', 'itemId', 'quantity', 'reason'], true)) {
            $this->reset('previewData', 'accepted');
            $this->resetValidation();
        }
    }

    public function loadOriginal(InvoiceXpressNoteIssue $issue): void
    {
        $this->authorizeAccount();
        $this->reset('originalData', 'previewData', 'accepted', 'line');
        $this->resetValidation();
        $this->validate(['originalId' => ['required', 'integer', 'min:1']], [
            'originalId.*' => 'Escolhe a fatura original.',
        ]);

        try {
            $original = $issue->original((int) $this->originalId);
            $this->originalData = [
                'number' => $original['number'],
                'lines' => array_map(fn (array $line): array => [
                    'name' => $line['article']['name'],
                    'quantity' => $line['quantity'],
                    'total' => $line['totals']['total'],
                ], $original['lines']),
            ];
        } catch (InvoiceXpressException $exception) {
            throw ValidationException::withMessages(['provider' => $exception->getMessage()]);
        }
    }

    public function preview(InvoiceXpressNoteIssue $issue): void
    {
        $this->authorizeAccount();
        $this->reset('previewData', 'accepted');
        $this->resetValidation();

        try {
            $preview = $issue->preview($this->input());
            $this->previewData = [
                'revision' => $preview['revision'],
                'number' => $preview['original']['number'],
                'customer' => $preview['original']['customer'],
                'line' => $preview['original']['lines'][$preview['input']['line'] - 1]['article']['name'],
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
        $this->authorizeAccount();
        $this->resetValidation();
        $this->validate(['accepted' => 'accepted'], ['accepted.accepted' => 'Confirma que autorizas a emissão da nota.']);

        if ($this->previewData === null) {
            throw ValidationException::withMessages(['confirmation' => 'Pré-visualiza os dados antes de emitir a nota.']);
        }

        try {
            $issue->confirm($this->input(), $this->previewData['revision']);
            $this->reset('previewData', 'accepted');
        } catch (InvoiceXpressException $exception) {
            throw ValidationException::withMessages(['provider' => $exception->getMessage()]);
        }
    }

    public function recover(int $attemptId, InvoiceXpressNoteIssue $issue): void
    {
        $this->authorizeAccount();
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
        $config = InvoiceXpressConfig::current();
        $options = InvoiceXpressIssue::options();

        return [
            'itemIds' => $options['itemIds'] ?? [],
            'hasConnection' => $config !== null,
            'canWriteDocuments' => InvoiceXpressIssue::canWriteDocuments(),
            'originals' => $options === null ? collect() : FiscalDocument::query()
                ->where('type', FiscalDocumentType::Invoice)
                ->where('status', FiscalDocumentStatus::Active)
                ->where('direction', FiscalDocumentDirection::Issued)
                ->where('currency', 'EUR')
                ->whereHas('invoiceXpressSnapshot', fn ($query) => $query->where('subdomain', $config->subdomain)
                    ->where('source->provider_type', 'Invoice'))
                ->latest('document_date')->latest('id')->limit(100)->get(),
            'attempts' => InvoiceXpressNoteAttempt::query()->where('state', '!=', InvoiceXpressNoteAttempt::Finalized)
                ->latest('id')->get()->concat(InvoiceXpressNoteAttempt::query()
                ->where('state', InvoiceXpressNoteAttempt::Finalized)->latest('id')->limit(25)->get()),
        ];
    }

    private function input(): array
    {
        return [
            'originalId' => $this->originalId,
            'kind' => $this->kind,
            'line' => $this->line,
            'itemId' => $this->itemId,
            'quantity' => $this->quantity,
            'reason' => $this->reason,
        ];
    }

    private function authorizeAccount(): void
    {
        abort_unless(static::canAccess() && $this->accountId === CurrentAccount::id(), 403);
    }
}
