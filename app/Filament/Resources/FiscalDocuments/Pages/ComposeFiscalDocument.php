<?php

namespace App\Filament\Resources\FiscalDocuments\Pages;

use App\Actions\IssueFiscalDocument;
use App\Actions\SaveFiscalDocumentDraft;
use App\Actions\SendFiscalDocumentByEmail;
use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Exceptions\InvoicingProviderException;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentPresenter;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Resources\FiscalDocuments\Schemas\FiscalDocumentDraftForm;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Models\Receivable;
use App\Support\FinanceSettings;
use App\Support\Invoicing\Invoicing;
use App\Support\Invoicing\InvoicingRecords;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * Where a document is written before it is issued: a new one of a given kind, a note or a receipt
 * on top of an invoice, or a draft being picked up again. Saving creates the draft at the
 * invoicing provider; issuing makes it official and cannot be undone.
 */
class ComposeFiscalDocument extends Page
{
    protected static string $resource = FiscalDocumentResource::class;

    /** @var array<string, mixed> */
    public ?array $data = [];

    #[Locked]
    public string $kind;

    #[Locked]
    public ?int $draftId = null;

    #[Locked]
    public ?int $originalId = null;

    public function mount(?string $type = null, int|string|null $record = null): void
    {
        abort_unless(Invoicing::canIssue(), 403);

        if (! Invoicing::connected()) {
            $this->redirect(FiscalDocumentResource::getUrl('create'));

            return;
        }

        $draft = $record === null ? null : FiscalDocument::query()->with(['lines', 'links.relatedFiscalDocument'])->findOrFail($record);

        if ($draft !== null && ! $draft->isDraft()) {
            $this->redirect(FiscalDocumentResource::getUrl('view', ['record' => $draft]));

            return;
        }

        $kind = $draft?->type ?? FiscalDocumentType::tryFrom((string) $type) ?? abort(404);
        $original = $draft?->links?->relatedFiscalDocument ?? $this->requestedOriginal($kind);

        abort_unless(Invoicing::provider()->capabilities()->supports($kind), 404);
        abort_if($kind === FiscalDocumentType::TransportGuide && ! app(FinanceSettings::class)->sellsGoods(), 404);

        $this->kind = $kind->value;
        $this->draftId = $draft?->getKey();
        $this->originalId = $original?->getKey();

        $this->form->fill($draft !== null
            ? FiscalDocumentDraftForm::state($draft)
            : [...FiscalDocumentDraftForm::defaults($kind, $original), ...$this->prefill($kind)]);
    }

    public function form(Schema $schema): Schema
    {
        $sections = FiscalDocumentDraftForm::components($this->type(), $this->original());
        $document = array_shift($sections);

        // As in the mock: who issues sits beside the document's header, and the lines take the full width below.
        return $schema->statePath('data')->components([
            Grid::make(['default' => 1, 'xl' => 3])->schema([
                $document->columnSpan(['default' => 1, 'xl' => 2]),
                $this->issueSection()->columnSpan(['default' => 1, 'xl' => 1]),
            ]),
            ...$sections,
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('saveDraft')
                ->footer([Actions::make($this->formActions())->key('form-actions')->alignEnd()->sticky()]),
        ]);
    }

    public function getTitle(): string
    {
        $original = $this->original();

        return match ($this->type()) {
            FiscalDocumentType::Invoice => __('New invoice'),
            FiscalDocumentType::InvoiceReceipt => __('New invoice receipt'),
            FiscalDocumentType::SimplifiedInvoice => __('New simplified invoice'),
            FiscalDocumentType::Quote => __('New quote'),
            FiscalDocumentType::ProForma => __('New pro forma invoice'),
            FiscalDocumentType::TransportGuide => __('New transport guide'),
            FiscalDocumentType::CreditNote => __('Credit note on :number', ['number' => $original?->number]),
            FiscalDocumentType::DebitNote => __('Debit note on :number', ['number' => $original?->number]),
            FiscalDocumentType::Receipt => __('Receipt for :number', ['number' => $original?->number]),
        };
    }

    public function getSubheading(): ?string
    {
        $provider = Invoicing::providerLabel();

        return match (true) {
            $this->type() === FiscalDocumentType::CreditNote => __('Choose what to correct. The invoice stays as it was issued; the note takes from its amount.'),
            $this->type() === FiscalDocumentType::DebitNote => __('To charge more than the invoice said: interest, expenses or a price adjustment. The invoice stays; the note adds to it.'),
            $this->type() === FiscalDocumentType::Receipt => __('Confirms to the client that they paid. The invoice stays as issued; the receipt settles it, in full or in part.'),
            ! $this->type()->countsAsBilling() && $this->type() !== FiscalDocumentType::TransportGuide => __('It is not an invoice: it does not count as billed. Saving creates the draft at :provider.', ['provider' => $provider]),
            default => __('Write the lines here. Saving creates the draft at :provider so you can check it there; issuing keeps the number, the ATCUD and the PDF.', ['provider' => $provider]),
        };
    }

    /** "Fiscal documents › FT 2026/13 › Credit note", so the way back is always one click away. */
    public function getBreadcrumbs(): array
    {
        $original = $this->original();

        return array_filter([
            FiscalDocumentResource::getUrl() => FiscalDocumentResource::getNavigationLabel(),
            ...($original === null ? [] : [FiscalDocumentResource::getUrl('view', ['record' => $original]) => FiscalDocumentPresenter::title($original)]),
            $original === null ? (string) $this->getTitle() : $this->type()->getLabel(),
        ]);
    }

    /** Saves the document and creates or updates its draft at the provider. */
    public function saveDraft(): void
    {
        $document = $this->persist();

        if ($document === null) {
            return;
        }

        Notification::make()->success()->title(__('Draft saved'))->send();
        $this->redirect(FiscalDocumentResource::getUrl('draft', ['record' => $document]));
    }

    /**
     * Saves what is on screen and then issues it: what the user sees is what becomes official.
     * Asked to, it then emails the issued document to the client; an email that cannot go yet
     * leaves the document issued and says so.
     */
    public function issue(bool $sendByEmail = false): void
    {
        $document = $this->persist();

        if ($document === null) {
            return;
        }

        $action = app(IssueFiscalDocument::class);

        try {
            $issued = $action->handle($document);
        } catch (ValidationException $exception) {
            $this->fail(collect($exception->errors())->flatten()->first(), $document);

            return;
        } catch (InvoicingProviderException $exception) {
            $this->fail($exception->getMessage(), $document);

            return;
        }

        Notification::make()->success()->title(__(':number issued', ['number' => $issued->number]))->send();

        foreach ($action->warnings as $warning) {
            Notification::make()->warning()->title(__('Issued, with something left to do'))->body($warning)->persistent()->send();
        }

        if ($sendByEmail) {
            $this->sendIssued($issued);
        }

        $this->redirect(FiscalDocumentResource::getUrl('view', ['record' => $issued]));
    }

    /** @return list<Action> */
    private function formActions(): array
    {
        $label = mb_strtolower($this->type()->getLabel());
        $issuable = (bool) Invoicing::connection()?->setting('issue_from_app', true);

        // The bar fills from its end, so the main action comes first to sit last on screen.
        return [
            Action::make('issue')
                ->label(__('Issue :type', ['type' => $label]))
                ->visible($issuable)
                ->requiresConfirmation()
                ->modalHeading(__('Issue :type', ['type' => $label]))
                ->modalDescription(__('Once issued, the document cannot be changed: it can only be cancelled or corrected with a credit note.'))
                ->modalSubmitActionLabel(__('Issue'))
                ->schema(fn (): array => $this->clientEmail() === null ? [] : [
                    Toggle::make('send_by_email')
                        ->label(__('Send to the client by email'))
                        ->helperText(fn (): string => __('With the PDF attached, to :email.', ['email' => $this->clientEmail()]))
                        ->default(false),
                ])
                ->action(fn (array $data) => $this->issue((bool) ($data['send_by_email'] ?? false))),
            Action::make('saveDraft')
                ->label(__('Save draft'))
                ->color('gray')
                ->submit('saveDraft')
                ->visible($this->type() !== FiscalDocumentType::Receipt),
            Action::make('cancel')
                ->label(__('Cancel'))
                ->color('gray')
                ->link()
                ->url(fn (): string => $this->originalId === null
                    ? FiscalDocumentResource::getUrl()
                    : FiscalDocumentResource::getUrl('view', ['record' => $this->originalId])),
        ];
    }

    /** Emails the document just issued to its client, saying what kept it from going. */
    private function sendIssued(FiscalDocument $document): void
    {
        $email = $document->client?->email;

        if (blank($email)) {
            return;
        }

        try {
            app(SendFiscalDocumentByEmail::class)->handle($document, $email);
        } catch (ValidationException $exception) {
            Notification::make()->warning()->title(__('Issued, but not sent by email'))
                ->body(collect($exception->errors())->flatten()->first().' '.__('You can send it from the document page.'))
                ->persistent()->send();

            return;
        }

        Notification::make()->success()->title(__('Sent to :email', ['email' => $email]))->send();
    }

    /** The email of the client the document on screen is for, when it has one. */
    private function clientEmail(): ?string
    {
        $clientId = $this->original()?->client_id ?? ($this->type() !== FiscalDocumentType::SimplifiedInvoice || ($this->data['consumer'] ?? null) === 'identified'
            ? ($this->data['client_id'] ?? null)
            : null);
        $email = blank($clientId) ? null : Client::query()->whereKey($clientId)->value('email');

        return filled($email) ? (string) $email : null;
    }

    /** Beside the form: who issues, which number comes next and how VAT is treated. */
    private function issueSection(): Section
    {
        return Section::make(__('Issue'))
            ->inlineLabel()
            ->extraAttributes(['class' => 'fap-key-value'])
            ->afterHeader([Text::make(__('Connected'))->badge()->color('success')])
            ->schema([
                TextEntry::make('issued_by')->label(__('Issued by'))->state(fn (): ?string => Invoicing::providerLabel()),
                TextEntry::make('remote_draft')->label(__('Draft'))
                    ->state(fn (): string => $this->draftId !== null && InvoicingRecords::for($this->draft()) !== null
                        ? __('Created at the provider')
                        : __('Created when you save')),
                TextEntry::make('next_number')->label(__('Next number'))->fontFamily('mono')->placeholder('—')
                    ->state(fn (): ?string => $this->nextNumber()),
                TextEntry::make('vat_regime')->label(__('VAT'))
                    ->state(fn (): string => app(FinanceSettings::class)->vatExempt() ? __('Exempt · article 53') : __('Charged on the lines')),
                TextEntry::make('issue_note')->hiddenLabel()->inlineLabel(false)->color('gray')
                    ->state(fn (): string => Invoicing::connection()?->setting('issue_from_app', true)
                        ? __('Once issued, the document cannot be changed: it can only be cancelled or corrected with a credit note.')
                        : __('Issuing from here is turned off: issue the draft at :provider and synchronise.', ['provider' => Invoicing::providerLabel()])),
            ]);
    }

    /** The number the provider would give this document, when it can be asked without failing the page. */
    private function nextNumber(): ?string
    {
        try {
            return Invoicing::provider()?->catalog()->nextNumber($this->type(), Invoicing::connection()?->setting('series'));
        } catch (InvoicingProviderException) {
            return null;
        }
    }

    /** Saves the form; null when it has errors, which are then on the fields. */
    private function persist(): ?FiscalDocument
    {
        $action = app(SaveFiscalDocumentDraft::class);
        $data = FiscalDocumentDraftForm::data($this->form->getState(), $this->type(), $this->original());

        try {
            $document = $action->handle($data, $this->draft());
        } catch (ValidationException $exception) {
            $this->showErrors($exception);

            return null;
        }

        $this->draftId = $document->getKey();

        if ($action->sendError !== null) {
            $this->fail($action->sendError, $document);

            return null;
        }

        return $document;
    }

    /**
     * Puts the domain's errors on the fields they are about. A line's error goes to that line,
     * which the form keys by an id of its own rather than by position.
     */
    private function showErrors(ValidationException $exception): void
    {
        $lineKeys = array_keys($this->data['lines'] ?? []);
        $fields = ['discount_basis_points' => 'discount'];
        $messages = [];

        foreach ($exception->errors() as $key => $errors) {
            if (preg_match('/\Alines\.(\d+)\.(.+)\z/', $key, $parts) && isset($lineKeys[(int) $parts[1]])) {
                $key = 'lines.'.$lineKeys[(int) $parts[1]].'.'.($fields[$parts[2]] ?? $parts[2]);
            }

            $messages["data.{$key}"] = $errors;
        }

        Notification::make()->danger()->title(collect($exception->errors())->flatten()->first())->send();

        throw ValidationException::withMessages($messages);
    }

    /** The draft is saved; what failed is the provider, so the user stays on it to try again. */
    private function fail(?string $message, FiscalDocument $document): void
    {
        Notification::make()->danger()->title(__('Saved here, but not issued'))->body($message)->persistent()->send();
        $this->redirect(FiscalDocumentResource::getUrl('draft', ['record' => $document]));
    }

    private function type(): FiscalDocumentType
    {
        return FiscalDocumentType::from($this->kind);
    }

    private function draft(): ?FiscalDocument
    {
        return $this->draftId === null ? null : once(fn (): ?FiscalDocument => FiscalDocument::query()->find($this->draftId));
    }

    private function original(): ?FiscalDocument
    {
        return $this->originalId === null ? null : once(fn (): ?FiscalDocument => FiscalDocument::query()->with(['lines', 'links.receivable'])->find($this->originalId));
    }

    /** A note or a receipt is only ever written on top of an issued invoice of this account. */
    private function requestedOriginal(FiscalDocumentType $kind): ?FiscalDocument
    {
        if (! $kind->requiresOriginal()) {
            return null;
        }

        $original = FiscalDocument::query()->with(['lines', 'links.receivable'])->find((int) request()->query('original'));

        abort_if($original === null || $original->status !== FiscalDocumentStatus::Active
            || $original->direction !== FiscalDocumentDirection::Issued || ! $original->type->isInvoice(), 404);

        return $original;
    }

    /**
     * What the address bar asks to start from: a receivable to invoice, or a quote to turn into
     * an invoice. Both are looked up within the account; anything else is ignored.
     *
     * @return array<string, mixed>
     */
    private function prefill(FiscalDocumentType $kind): array
    {
        $receivable = Receivable::query()->excludingCancelled()->with('billable')->find((int) request()->query('receivable'));
        $source = FiscalDocument::query()->with('lines')->find((int) request()->query('from'));

        if ($source !== null && in_array($source->type, [FiscalDocumentType::Quote, FiscalDocumentType::ProForma], true)) {
            $state = FiscalDocumentDraftForm::state($source);

            return ['client_id' => $state['client_id'], 'consumer' => 'identified', 'lines' => $state['lines'], 'notes' => $state['notes'], 'vat_exemption_reason' => $state['vat_exemption_reason']];
        }

        if ($receivable === null || $receivable->client === null || ! $kind->isInvoice()) {
            return [];
        }

        $defaults = FiscalDocumentDraftForm::defaults($kind);

        return [
            'client_id' => $receivable->client->getKey(),
            'consumer' => 'identified',
            'receivable_id' => $receivable->getKey(),
            'due_date' => $kind === FiscalDocumentType::Invoice ? max($receivable->due_on->toDateString(), today()->toDateString()) : null,
            'lines' => [[...$defaults['lines'][0], 'description' => $receivable->label, 'unit_price' => (int) $receivable->amount]],
        ];
    }
}
