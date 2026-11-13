<?php

namespace App\Filament\Pages;

use App\Actions\ConfirmInvoiceImport;
use App\Actions\DecideInvoiceImport;
use App\Actions\ReadImportedInvoice;
use App\Actions\ReceiveInvoiceImport;
use App\Enums\ExpenseAttribution;
use App\Enums\FiscalDocumentType;
use App\Enums\InvoiceImportStatus;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Http\Controllers\InvoiceImportFileController;
use App\Models\Category;
use App\Models\Expense;
use App\Models\InvoiceImport;
use App\Support\CurrentAccount;
use App\Support\FiscalTerms;
use App\Support\InvoiceImportDraft;
use App\Support\Money;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Pages\PageConfiguration;
use Filament\Panel;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * "Importar faturas": one inbox of invoices to confirm. The person drops PDFs or photos, the app
 * reads each one and says, in a sentence, what it will become; the person answers "that's right"
 * (and it is saved at once), corrects it, or leaves it for later. Nothing is imported unseen.
 */
class InvoiceImportInbox extends Page
{
    use WithFileUploads;

    protected static ?string $slug = 'importar-faturas';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::UploadSimple;

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.invoice-import-inbox';

    /** @var array<int, mixed> */
    public array $files = [];

    #[Locked]
    public ?int $accountId = null;

    /** The invoice in view. */
    public ?int $current = null;

    /** What the panel shows for it: the summary to confirm, the fields to correct, or the expenses to pick from. */
    public string $mode = 'confirm';

    /** @var array<string, mixed> the fields of "Corrigir" and of "Preencher à mão" */
    public array $form = [];

    public string $expenseSearch = '';

    public ?string $picked = null;

    /** The unreadable invoice a new photo is replacing. */
    #[Locked]
    public ?int $replacing = null;

    public static function canAccess(): bool
    {
        return CurrentAccount::get() !== null && Gate::allows('viewAny', Expense::class);
    }

    #[Unexposed]
    public static function routes(Panel $panel, ?PageConfiguration $configuration = null): void
    {
        parent::routes($panel, $configuration);

        // The private file of one invoice, for the page to show beside what was read from it.
        Route::get(static::getRoutePath($panel).'/ficheiro/{import}', InvoiceImportFileController::class)
            ->whereNumber('import')
            ->middleware(static::getRouteMiddleware($panel))
            ->withoutMiddleware(static::getWithoutRouteMiddleware($panel))
            ->name(static::getRelativeRouteName($panel).'.ficheiro');
    }

    public function mount(): void
    {
        $this->accountId = CurrentAccount::id();
        $this->current = $this->nextWaiting()?->id;
    }

    public function hydrate(): void
    {
        abort_unless($this->accountId === CurrentAccount::id(), 403);
    }

    public function getSubheading(): ?string
    {
        return $this->imports()->isEmpty()
            ? __('Drop the invoices you received here. Contiu reads them and you only confirm.')
            : __('Confirm what we read on each invoice. We take care of the rest.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('expenses')
                ->label(__('Expenses'))
                ->link()
                ->color('gray')
                ->url(fn (): string => ExpenseResource::getUrl()),
        ];
    }

    protected function getViewData(): array
    {
        $imports = $this->imports();
        $import = $imports->firstWhere('id', $this->current);
        $decide = app(DecideInvoiceImport::class);
        $decision = $import?->status === InvoiceImportStatus::Read ? $decide->handle($import) : null;

        return [
            'imports' => $imports,
            'import' => $import,
            'decision' => $decision,
            'states' => $imports->mapWithKeys(fn (InvoiceImport $item): array => [
                $item->id => $item->is($import) && $decision !== null ? $this->state($item, $decision) : $this->state($item, $item->status === InvoiceImportStatus::Read ? $decide->handle($item) : null),
            ])->all(),
            'handled' => $imports->filter(fn (InvoiceImport $item): bool => $item->status->isHandled())->count(),
            'reading' => $imports->contains(fn (InvoiceImport $item): bool => $item->status->isReading()),
            // Nothing left to confirm or to read: the summary, unless the person opened an invoice that could not be read.
            'allDone' => $imports->contains(fn (InvoiceImport $item): bool => $item->status->isHandled())
                && $imports->every(fn (InvoiceImport $item): bool => $item->status->isHandled() || $item->status === InvoiceImportStatus::Unreadable)
                && ($import === null || $import->status->isHandled()),
            'categories' => Category::query()->expense()->pluck('name', 'id')->all(),
            'rates' => Money::vatOptions(),
            'choices' => $this->mode === 'pick' && $import !== null ? $decide->expenseChoices($import, $this->expenseSearch) : collect(),
            'canConfirm' => ConfirmInvoiceImport::canConfirm(),
            'usesAi' => ReadImportedInvoice::usesAi(),
            'csvUrl' => ExpenseDocumentImport::getUrl(),
            'fileBase' => static::getUrl().'/ficheiro/',
        ];
    }

    /** The files just dropped or photographed: each becomes an invoice in the list and is read in the background. */
    public function updatedFiles(ReceiveInvoiceImport $receive): void
    {
        abort_unless(ConfirmInvoiceImport::canConfirm(), 403);
        $this->resetValidation('files');
        $files = array_values(array_filter($this->files));
        $this->files = [];

        if (count($files) > InvoiceImport::BATCH) {
            throw ValidationException::withMessages(['files' => __('Choose up to :count invoices at a time.', ['count' => InvoiceImport::BATCH])]);
        }

        $refused = [];
        $first = null;
        foreach ($files as $file) {
            try {
                $received = $receive->handle($file);
                $first ??= $received;
            } catch (DomainException $exception) {
                $refused[] = $file->getClientOriginalName().' · '.$exception->getMessage();
            } finally {
                $file->delete();
            }
        }

        if ($first !== null && $this->replacing !== null) {
            app(ConfirmInvoiceImport::class)->dismiss($this->replacing);
        }
        $this->replacing = null;

        if ($first !== null && ($this->current === null || $this->currentImport()?->status->isHandled() !== false)) {
            $this->open($first->id);
        }

        if ($refused !== []) {
            throw ValidationException::withMessages(['files' => $refused]);
        }
    }

    /** "Tirar outra fotografia": the next file dropped takes the place of the invoice that could not be read. */
    public function replace(): void
    {
        $this->replacing = $this->currentImport()?->status === InvoiceImportStatus::Unreadable ? $this->current : null;
    }

    public function open(int $id): void
    {
        $this->current = InvoiceImport::query()->whereKey($id)->value('id');
        $this->mode = 'confirm';
        $this->reset('form', 'expenseSearch', 'picked');
        $this->resetValidation();
    }

    /** "Está certo": the invoice is saved now, as the panel said it would be. */
    public function confirm(ConfirmInvoiceImport $confirm): void
    {
        $this->act(fn () => $confirm->expense((int) $this->current));
    }

    /** "Registar como venda", for an invoice the account itself issued. */
    public function registerSale(ConfirmInvoiceImport $confirm): void
    {
        $this->act(fn () => $confirm->sale((int) $this->current));
    }

    /** "Não é uma despesa minha", "Já a tenho registada", "Tirar da lista". */
    public function dismiss(ConfirmInvoiceImport $confirm): void
    {
        $this->act(fn () => $confirm->dismiss((int) $this->current));
    }

    /** "Deixar para depois": on to the next one, this one stays in the list. */
    public function later(): void
    {
        $this->open($this->nextWaiting(after: $this->current)?->id ?? (int) $this->current);
    }

    public function setCategory(?string $category): void
    {
        $this->choose(['category_id' => filled($category) && Category::query()->expense()->whereKey($category)->exists() ? (int) $category : null]);
    }

    /** "Qual é o total certo?": the total printed on the invoice, or the base plus the VAT. */
    public function answerTotal(string $answer): void
    {
        $import = $this->waitingImport();
        $draft = $import->data;

        if (! isset($draft['net'], $draft['vat'], $draft['total'])) {
            return;
        }

        $rates = $answer === 'invoice'
            ? [['rate' => InvoiceImportDraft::rateFor($draft['net'], $draft['total'] - $draft['net']), 'net' => $draft['net'], 'vat' => $draft['total'] - $draft['net']]]
            : $draft['rates'];

        if ($rates[0]['vat'] < 0) {
            return;
        }

        $import->update(['data' => InvoiceImportDraft::corrected($draft, ['rates' => $rates])]);
    }

    /** "É a despesa que já lançaste?" */
    public function answerExpense(string $answer, DecideInvoiceImport $decide): void
    {
        $import = $this->waitingImport();
        $similar = $decide->handle($import)['similar'];

        $this->choose($answer === 'yes' && $similar !== null ? ['expense' => $similar->id, 'adjust' => true] : ['expense' => 'new']);
    }

    /** "Corrigir ou ver mais detalhes": the same fields, to edit. */
    public function correct(DecideInvoiceImport $decide): void
    {
        $import = $this->waitingImport();
        $draft = $import->data;
        $decision = $decide->handle($import);

        $this->form = [
            'supplier_name' => $decision['supplier']?->name ?? ($draft['supplier_name'] ?? ''),
            'supplier_nif' => $draft['supplier_nif'] ?? '',
            'number' => $draft['number'] ?? '',
            'date' => $draft['date'] ?? '',
            'description' => $draft['description'] ?? (count($draft['lines'] ?? []) === 1 ? $draft['lines'][0]['description'] : ''),
            'rates' => array_map(fn (array $rate): array => [
                'rate' => $rate['rate'], 'net' => $this->euros($rate['net']), 'vat' => $this->euros($rate['vat']),
            ], $draft['rates'] ?: [['rate' => '0.2300', 'net' => 0, 'vat' => 0]]),
            'category_id' => (string) ($decision['category_id'] ?? ''),
            'attribution' => $decision['attribution'],
            'is_paid' => $decision['is_paid'],
            'bank' => $decision['bank'] !== null,
        ];
        $this->mode = 'correct';
        $this->resetValidation();
    }

    public function addRate(): void
    {
        if (count($this->form['rates'] ?? []) < 4) {
            $this->form['rates'][] = ['rate' => '0.0600', 'net' => '', 'vat' => ''];
        }
    }

    public function removeRate(int $index): void
    {
        if (count($this->form['rates'] ?? []) > 1) {
            unset($this->form['rates'][$index]);
            $this->form['rates'] = array_values($this->form['rates']);
        }
    }

    /** "Guardar e confirmar": what was corrected replaces what was read, and the invoice is saved. */
    public function saveCorrection(ConfirmInvoiceImport $confirm, bool $thenConfirm = true): void
    {
        $import = $this->waitingImport();
        $data = $this->validate([
            'form.supplier_name' => ['required', 'string', 'max:255'],
            'form.supplier_nif' => ['nullable', 'string', fn (string $attribute, mixed $value, \Closure $fail) => FiscalTerms::nif($value) === null ? $fail(__('Enter a valid Portuguese tax ID, or leave it empty.')) : null],
            'form.number' => ['nullable', 'string', 'max:100'],
            'form.date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'form.description' => ['nullable', 'string', 'max:250'],
            'form.rates' => ['required', 'array', 'min:1', 'max:4'],
            'form.rates.*.rate' => ['required', 'distinct', 'in:'.implode(',', array_keys(Money::vatOptions()))],
            'form.rates.*.net' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'form.rates.*.vat' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'form.category_id' => ['nullable'],
            'form.attribution' => ['required', 'in:'.implode(',', array_column(ExpenseAttribution::cases(), 'value'))],
            'form.is_paid' => ['boolean'],
            'form.bank' => ['boolean'],
        ], [
            'form.supplier_name.required' => __('Enter the supplier.'),
            'form.date.required' => __('Enter the date of the invoice.'),
            'form.date.date_format' => __('Enter the date of the invoice.'),
            'form.date.before_or_equal' => __('The date of the invoice cannot be in the future.'),
            'form.rates.*.rate.distinct' => __('Each VAT rate can appear only once.'),
            'form.rates.*.net.required' => __('Enter the amount excluding VAT.'),
            'form.rates.*.net.numeric' => __('Enter the amount excluding VAT.'),
            'form.rates.*.vat.required' => __('Enter the VAT.'),
            'form.rates.*.vat.numeric' => __('Enter the VAT.'),
        ])['form'];

        $rates = array_map(fn (array $rate): array => [
            'rate' => $rate['rate'], 'net' => (int) Money::toCents($this->number($rate['net'])), 'vat' => (int) Money::toCents($this->number($rate['vat'])),
        ], array_values($data['rates']));

        if (array_sum(array_column($rates, 'net')) + array_sum(array_column($rates, 'vat')) <= 0) {
            throw ValidationException::withMessages(['form.rates' => __('Enter the amounts of the invoice.')]);
        }

        $draft = InvoiceImportDraft::corrected($import->data ?? [], [
            'supplier_name' => trim($data['supplier_name']),
            'supplier_nif' => FiscalTerms::nif($data['supplier_nif'] ?? null),
            'number' => filled($data['number'] ?? null) ? trim($data['number']) : null,
            'date' => $data['date'],
            'rates' => $rates,
        ]);
        $draft['description'] = filled($data['description'] ?? null) ? trim($data['description']) : null;
        $draft['paid'] = (bool) $data['is_paid'];

        $import->update([
            'data' => $draft,
            'choices' => [
                ...($import->choices ?? []),
                'category_id' => filled($data['category_id']) && Category::query()->expense()->whereKey($data['category_id'])->exists() ? (int) $data['category_id'] : null,
                'attribution' => $data['attribution'],
                'is_paid' => (bool) $data['is_paid'],
                'bank' => $data['bank'] ? null : 'none',
            ],
        ]);

        $this->mode = 'confirm';
        $this->reset('form');

        if ($thenConfirm) {
            $this->act(fn () => $confirm->expense($import->id));
        }
    }

    /** "Juntar a uma despesa que já existe…": the expenses to pick from. */
    public function pickExpense(): void
    {
        $import = $this->waitingImport();
        $this->mode = 'pick';
        $chosen = $import->choices['expense'] ?? null;
        $this->picked = $chosen === null ? null : (string) $chosen;
        $this->expenseSearch = '';
    }

    /** "Juntar a esta despesa", taking the invoice's amounts or keeping the expense's own. */
    public function joinExpense(bool $adjust = true): void
    {
        $this->waitingImport();

        if ($this->picked === null) {
            throw ValidationException::withMessages(['picked' => __('Choose the expense, or "None of these".')]);
        }

        $this->choose($this->picked === 'new' ? ['expense' => 'new'] : ['expense' => (int) $this->picked, 'adjust' => $adjust]);
        $this->mode = 'confirm';
    }

    public function back(): void
    {
        $this->mode = 'confirm';
        $this->reset('form', 'expenseSearch', 'picked');
        $this->resetValidation();
    }

    /** "Preencher à mão", for a file that could not be read: the essentials, typed. */
    public function fillByHand(): void
    {
        $import = $this->currentImport();
        abort_unless($import?->status === InvoiceImportStatus::Unreadable, 404);

        $this->form = ['supplier_name' => '', 'supplier_nif' => '', 'number' => '', 'date' => today()->toDateString(), 'total' => '', 'rate' => '0.2300', 'category_id' => ''];
        $this->mode = 'manual';
        $this->resetValidation();
    }

    /** "Guardar despesa": the typed invoice becomes an expense with the file as its supporting document. */
    public function saveByHand(ConfirmInvoiceImport $confirm): void
    {
        abort_unless(ConfirmInvoiceImport::canConfirm(), 403);
        $import = $this->currentImport();
        abort_unless($import?->status === InvoiceImportStatus::Unreadable, 404);

        $data = $this->validate([
            'form.supplier_name' => ['required', 'string', 'max:255'],
            'form.supplier_nif' => ['nullable', 'string', fn (string $attribute, mixed $value, \Closure $fail) => FiscalTerms::nif($value) === null ? $fail(__('Enter a valid Portuguese tax ID, or leave it empty.')) : null],
            'form.number' => ['nullable', 'string', 'max:100'],
            'form.date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'form.total' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'form.rate' => ['required', 'in:'.implode(',', array_keys(Money::vatOptions()))],
            'form.category_id' => ['nullable'],
        ], [
            'form.supplier_name.required' => __('Enter the supplier.'),
            'form.date.required' => __('Enter the date of the invoice.'),
            'form.date.date_format' => __('Enter the date of the invoice.'),
            'form.date.before_or_equal' => __('The date of the invoice cannot be in the future.'),
            'form.total.required' => __('Enter the total of the invoice, with VAT.'),
            'form.total.numeric' => __('Enter the total of the invoice, with VAT.'),
            'form.total.gt' => __('Enter the total of the invoice, with VAT.'),
        ])['form'];

        $net = Money::netFromGross((int) Money::toCents($this->number($data['total'])), $data['rate']);
        $draft = InvoiceImportDraft::corrected([
            'type' => filled($data['number'] ?? null) ? FiscalDocumentType::Invoice->value : null,
            'due_date' => null, 'atcud' => null, 'customer_name' => null, 'customer_nif' => null, 'lines' => [], 'paid' => true, 'exact' => [],
        ], [
            'supplier_name' => trim($data['supplier_name']),
            'supplier_nif' => FiscalTerms::nif($data['supplier_nif'] ?? null),
            'number' => filled($data['number'] ?? null) ? trim($data['number']) : null,
            'date' => $data['date'],
            'rates' => [['rate' => $data['rate'], 'net' => $net, 'vat' => Money::vat($net, $data['rate'])]],
        ]);

        $import->update([
            'status' => InvoiceImportStatus::Read,
            'error' => null,
            'data' => $draft,
            'choices' => ['category_id' => filled($data['category_id']) && Category::query()->expense()->whereKey($data['category_id'])->exists() ? (int) $data['category_id'] : null],
        ]);

        $this->mode = 'confirm';
        $this->reset('form');
        $this->act(fn () => $confirm->expense($import->id));
    }

    /** "Importar mais faturas": what is done leaves the list. */
    public function startOver(ConfirmInvoiceImport $confirm): void
    {
        $confirm->clearHandled();
        $this->open($this->nextWaiting()?->id ?? 0);
    }

    /** The amounts "Preencher à mão" works out from the total and the rate, for the page to show. */
    public function byHandAmounts(): ?array
    {
        $total = Money::toCents($this->number($this->form['total'] ?? ''));
        $rate = $this->form['rate'] ?? null;

        if ($total === null || $total <= 0 || ! array_key_exists((string) $rate, Money::vatOptions())) {
            return null;
        }

        $net = Money::netFromGross($total, $rate);
        $vat = Money::vat($net, $rate);

        return ['net' => $net, 'vat' => $vat, 'total' => $net + $vat, 'typed' => $total];
    }

    /** @return Collection<int, InvoiceImport> */
    private function imports(): Collection
    {
        return once(fn (): Collection => InvoiceImport::query()->orderBy('id')->limit(100)->get());
    }

    private function currentImport(): ?InvoiceImport
    {
        return $this->current === null ? null : InvoiceImport::query()->find($this->current);
    }

    /** The invoice in view, still waiting to be confirmed, by someone who may confirm it. */
    private function waitingImport(): InvoiceImport
    {
        abort_unless(ConfirmInvoiceImport::canConfirm(), 403);
        $import = $this->currentImport();
        abort_unless($import?->status === InvoiceImportStatus::Read, 404);

        return $import;
    }

    /** The next invoice to confirm, going down the list and back to the top; while none is read yet, the first being read. */
    private function nextWaiting(?int $after = null): ?InvoiceImport
    {
        $waiting = InvoiceImport::query()->where('status', InvoiceImportStatus::Read)->orderBy('id')->get();

        return $waiting->first(fn (InvoiceImport $import): bool => $after !== null && $import->id > $after)
            ?? $waiting->first(fn (InvoiceImport $import): bool => $import->id !== $after)
            ?? InvoiceImport::query()->whereIn('status', [InvoiceImportStatus::Queued, InvoiceImportStatus::Reading])->orderBy('id')->first();
    }

    /** @param array<string, mixed> $choices */
    private function choose(array $choices): void
    {
        $import = $this->waitingImport();
        $import->update(['choices' => [...($import->choices ?? []), ...$choices]]);
    }

    /** Runs what a button asked for and moves on to the next invoice; a refusal is told on the panel. */
    private function act(callable $action): void
    {
        $this->resetValidation();

        try {
            $action();
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['current' => $exception->getMessage()]);
        }

        $this->open($this->nextWaiting(after: $this->current)?->id ?? 0);
    }

    /**
     * The line under each invoice in the list.
     *
     * @param  array<string, mixed>|null  $decision
     * @return array{label: string, tone: string, name: string}
     */
    private function state(InvoiceImport $import, ?array $decision): array
    {
        $draft = $import->data ?? [];
        // An invoice the account issued is known by who it was issued to.
        $ownInvoice = $decision !== null ? $decision['kind'] === 'sale' : $import->status === InvoiceImportStatus::Sale;
        $name = ($ownInvoice ? ($draft['customer_name'] ?? null) : null) ?? $draft['supplier_name'] ?? $import->filename;

        return ['name' => $name] + match ($import->status) {
            InvoiceImportStatus::Queued, InvoiceImportStatus::Reading => ['label' => __('Reading…'), 'tone' => 'reading'],
            InvoiceImportStatus::Unreadable => ['label' => __('We could not read it'), 'tone' => 'fail'],
            InvoiceImportStatus::Confirmed => ['label' => __('Invoice confirmed'), 'tone' => 'done'],
            InvoiceImportStatus::Sale => ['label' => __('Sale recorded'), 'tone' => 'done'],
            InvoiceImportStatus::Duplicate => ['label' => __('Was already imported'), 'tone' => 'done'],
            InvoiceImportStatus::Dismissed => ['label' => __('Left out'), 'tone' => 'done'],
            InvoiceImportStatus::Read => match (true) {
                $decision === null => ['label' => __('Invoice to confirm'), 'tone' => 'todo'],
                $decision['kind'] === 'sale' => ['label' => __('Is it a sale?'), 'tone' => 'ask'],
                $decision['kind'] === 'duplicate' => ['label' => __('Was already imported'), 'tone' => 'ask'],
                $decision['missing'] !== [] => ['label' => __('Details missing'), 'tone' => 'ask'],
                $decision['questions'] !== [] => ['label' => trans_choice('{1} Has 1 question|[2,*] Has :count questions', count($decision['questions'])), 'tone' => 'ask'],
                default => ['label' => __('Invoice to confirm'), 'tone' => 'todo'],
            },
        };
    }

    private function euros(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '');
    }

    /** "12,30" as typed, for the numeric rules and for Money. */
    private function number(mixed $value): string
    {
        return str_replace([' ', ','], ['', '.'], trim((string) $value));
    }

    protected function prepareForValidation($attributes): array
    {
        foreach (['total'] as $field) {
            if (isset($attributes['form'][$field])) {
                $attributes['form'][$field] = $this->number($attributes['form'][$field]);
            }
        }

        foreach ($attributes['form']['rates'] ?? [] as $index => $rate) {
            foreach (['net', 'vat'] as $field) {
                $attributes['form']['rates'][$index][$field] = $this->number($rate[$field] ?? '');
            }
        }

        return $attributes;
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return __('Import invoices');
    }

    #[Unexposed]
    public static function getNavigationGroup(): string
    {
        return __('Expenses');
    }

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('Import invoices');
    }
}
