<?php

namespace App\Filament\Pages;

use App\Actions\ConfirmInvoiceImport;
use App\Actions\DecideInvoiceImport;
use App\Actions\ReadImportedInvoice;
use App\Actions\ReceiveInvoiceImport;
use App\Enums\ExpenseAttribution;
use App\Enums\ExpenseKind;
use App\Enums\FiscalDocumentType;
use App\Enums\InvoiceImportStatus;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Http\Controllers\InvoiceImportFileController;
use App\Models\Category;
use App\Models\Expense;
use App\Models\InvoiceImport;
use App\Models\Project;
use App\Models\Retainer;
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
use Filament\Support\Enums\Width;
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

    /** The workspace takes the whole screen; the page's own CSS keeps the two cards at the panel's usual width. */
    protected Width|string|null $maxContentWidth = Width::Full;

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

    /** @var list<array{description: string, quantity: string, unit_price: string, rate: string}> the rows of "Linhas da fatura" */
    public array $lines = [];

    /** Where "Linhas da fatura" was opened from, to go back there. */
    public string $linesFrom = 'confirm';

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

        // Nothing left to confirm or to read: the summary, unless the person opened an invoice that could not be read.
        $allDone = $imports->contains(fn (InvoiceImport $item): bool => $item->status->isHandled())
            && $imports->every(fn (InvoiceImport $item): bool => $item->status->isHandled() || $item->status === InvoiceImportStatus::Unreadable)
            && ($import === null || $import->status->isHandled());

        return [
            'imports' => $imports,
            'import' => $import,
            'decision' => $decision,
            'states' => $imports->mapWithKeys(fn (InvoiceImport $item): array => [
                $item->id => $item->is($import) && $decision !== null ? $this->state($item, $decision) : $this->state($item, $item->status === InvoiceImportStatus::Read ? $decide->handle($item) : null),
            ])->all(),
            'handled' => $imports->filter(fn (InvoiceImport $item): bool => $item->status->isHandled())->count(),
            'reading' => $imports->contains(fn (InvoiceImport $item): bool => $item->status->isReading()),
            'allDone' => $allDone,
            // The three columns: the list, the invoice and what it will become.
            'work' => $imports->isNotEmpty() && ! $allDone,
            'categories' => Category::query()->expense()->pluck('name', 'id')->all(),
            'rates' => Money::vatOptions(),
            'costCentres' => $this->mode === 'correct' ? $this->costCentres() : [],
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
        $this->reset('form', 'lines', 'linesFrom', 'expenseSearch', 'picked');
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

    /** "Corrigir ou ver mais detalhes": the same fields, to edit. A sale has its client where an expense has its supplier. */
    public function correct(DecideInvoiceImport $decide): void
    {
        $import = $this->waitingImport();
        $draft = $import->data;
        $decision = $decide->handle($import);
        $isSale = $decision['kind'] === 'sale';

        $this->form = [
            'supplier_name' => $isSale
                ? ($decision['client']?->name ?? ($draft['customer_name'] ?? ''))
                : ($decision['supplier']?->name ?? ($draft['supplier_name'] ?? '')),
            'supplier_nif' => ($isSale ? ($draft['customer_nif'] ?? null) : ($draft['supplier_nif'] ?? null)) ?? '',
            'number' => $draft['number'] ?? '',
            'date' => $draft['date'] ?? '',
            'due_date' => $draft['due_date'] ?? '',
            'description' => $draft['description'] ?? (count($draft['lines'] ?? []) === 1 ? $draft['lines'][0]['description'] : ''),
            'rates' => array_map(fn (array $rate): array => [
                'rate' => $rate['rate'], 'net' => $this->euros($rate['net']), 'vat' => $this->euros($rate['vat']),
            ], $draft['rates'] ?: [['rate' => '0.2300', 'net' => 0, 'vat' => 0]]),
            'category_id' => (string) ($decision['category_id'] ?? ''),
            'kind' => $decision['kind_of_cost'],
            'cost_centre' => $decision['cost_centre'] ?? '',
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
    public function saveCorrection(ConfirmInvoiceImport $confirm, DecideInvoiceImport $decide, bool $thenConfirm = true): void
    {
        $import = $this->waitingImport();
        $isSale = $decide->handle($import)['kind'] === 'sale';
        $taxId = fn (string $attribute, mixed $value, \Closure $fail) => FiscalTerms::nif($value) === null ? $fail(__('Enter a valid Portuguese tax ID, or leave it empty.')) : null;

        $data = $this->validate([
            'form.supplier_name' => ['required', 'string', 'max:255'],
            'form.supplier_nif' => ['nullable', 'string', $taxId],
            'form.number' => [$isSale ? 'required' : 'nullable', 'string', 'max:100'],
            'form.date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'form.due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:form.date'],
            'form.description' => ['nullable', 'string', 'max:250'],
            'form.rates' => ['required', 'array', 'min:1', 'max:4'],
            'form.rates.*.rate' => ['required', 'distinct', 'in:'.implode(',', array_keys(Money::vatOptions()))],
            'form.rates.*.net' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'form.rates.*.vat' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'form.category_id' => ['nullable'],
            'form.kind' => ['required', 'in:'.implode(',', array_column(ExpenseKind::cases(), 'value'))],
            'form.cost_centre' => ['nullable', 'string', 'regex:/\A(project|retainer):[1-9][0-9]{0,17}\z/'],
            'form.attribution' => ['required', 'in:'.implode(',', array_column(ExpenseAttribution::cases(), 'value'))],
            'form.is_paid' => ['boolean'],
            'form.bank' => ['boolean'],
        ], [
            'form.supplier_name.required' => $isSale ? __('Enter the client.') : __('Enter the supplier.'),
            'form.number.required' => __('Enter the number of the invoice.'),
            'form.date.required' => __('Enter the date of the invoice.'),
            'form.date.date_format' => __('Enter the date of the invoice.'),
            'form.date.before_or_equal' => __('The date of the invoice cannot be in the future.'),
            'form.due_date.after_or_equal' => __('The due date cannot be before the date of the invoice.'),
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

        $party = $isSale ? 'customer' : 'supplier';
        $draft = InvoiceImportDraft::corrected($import->data ?? [], [
            $party.'_name' => trim($data['supplier_name']),
            $party.'_nif' => FiscalTerms::nif($data['supplier_nif'] ?? null),
            'number' => filled($data['number'] ?? null) ? trim($data['number']) : null,
            'date' => $data['date'],
            'rates' => $rates,
            ...($isSale ? ['due_date' => filled($data['due_date'] ?? null) ? $data['due_date'] : null] : []),
        ]);

        if ($isSale) {
            $import->update(['data' => $draft]);
        } else {
            $draft['description'] = filled($data['description'] ?? null) ? trim($data['description']) : null;
            $draft['paid'] = (bool) $data['is_paid'];
            $import->update(['data' => $draft, 'choices' => [
                ...($import->choices ?? []),
                'category_id' => filled($data['category_id']) && Category::query()->expense()->whereKey($data['category_id'])->exists() ? (int) $data['category_id'] : null,
                ...$this->costCentre($data['kind'], (string) ($data['cost_centre'] ?? '')),
                'attribution' => $data['attribution'],
                'is_paid' => (bool) $data['is_paid'],
                'bank' => $data['bank'] ? null : 'none',
            ]]);
        }

        $this->mode = 'confirm';
        $this->reset('form');

        if ($thenConfirm) {
            $this->act(fn () => $isSale ? $confirm->sale($import->id) : $confirm->expense($import->id));
        }
    }

    /**
     * "Tipo de custo": a direct cost names the sale or the retainer it belongs to, one of the account's own.
     *
     * @return array{kind: string, project_id: ?int, retainer_id: ?int}
     */
    private function costCentre(string $kind, string $costCentre): array
    {
        if ($kind !== ExpenseKind::Direct->value) {
            return ['kind' => ExpenseKind::Overhead->value, 'project_id' => null, 'retainer_id' => null];
        }

        [$type, $id] = array_pad(explode(':', $costCentre, 2), 2, '');
        $exists = match ($type) {
            'project' => Project::query()->whereKey($id)->exists(),
            'retainer' => Retainer::query()->whereKey($id)->exists(),
            default => false,
        };

        if (! $exists) {
            throw ValidationException::withMessages(['form.cost_centre' => __('Choose the sale or the retainer this cost belongs to.')]);
        }

        return ['kind' => $kind, 'project_id' => $type === 'project' ? (int) $id : null, 'retainer_id' => $type === 'retainer' ? (int) $id : null];
    }

    /** "Linhas da fatura": what was bought, line by line, to read and to edit. */
    public function editLines(): void
    {
        $import = $this->waitingImport();
        $draft = $import->data;
        $rate = $draft['rates'][0]['rate'] ?? '0.2300';

        $this->lines = array_map(fn (array $line): array => [
            'description' => $line['description'],
            'quantity' => str_replace('.', ',', (string) $line['quantity']),
            'unit_price' => $line['unit_price'] === null ? '' : $this->euros($line['unit_price']),
            'rate' => $line['rate'] ?? $rate,
        ], $draft['lines'] ?? []) ?: [['description' => '', 'quantity' => '1', 'unit_price' => '', 'rate' => $rate]];
        $this->linesFrom = $this->mode === 'correct' ? 'correct' : 'confirm';
        $this->mode = 'lines';
        $this->resetValidation();
    }

    public function addLine(): void
    {
        if (count($this->lines) < InvoiceImportDraft::MAX_LINES) {
            $this->lines[] = ['description' => '', 'quantity' => '1', 'unit_price' => '', 'rate' => $this->lines[array_key_last($this->lines)]['rate'] ?? '0.2300'];
        }
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    /** "Guardar linhas": the lines as typed replace the ones read. The amounts of the invoice stay as they are. */
    public function saveLines(): void
    {
        $import = $this->waitingImport();
        $rows = $this->validate([
            'lines' => ['array', 'max:'.InvoiceImportDraft::MAX_LINES],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'lines.*.rate' => ['required', 'in:'.implode(',', array_keys(Money::vatOptions()))],
        ], [
            'lines.*.description.required' => __('Describe the line, or remove it.'),
            'lines.*.quantity.required' => __('Enter the quantity.'),
            'lines.*.quantity.numeric' => __('Enter the quantity.'),
            'lines.*.quantity.gt' => __('Enter the quantity.'),
            'lines.*.unit_price.required' => __('Enter the price excluding VAT.'),
            'lines.*.unit_price.numeric' => __('Enter the price excluding VAT.'),
        ])['lines'] ?? [];

        $import->update(['data' => [...$import->data, 'lines' => array_map(fn (array $row): array => InvoiceImportDraft::line(
            trim($row['description']), $this->number($row['quantity']), (int) Money::toCents($this->number($row['unit_price'])), $row['rate'],
        ), array_values($rows))]]);

        $this->closeLines();
    }

    /** "Voltar sem guardar". */
    public function closeLines(): void
    {
        $this->mode = $this->linesFrom === 'correct' && $this->form !== [] ? 'correct' : 'confirm';
        $this->reset('lines', 'linesFrom');
        $this->resetValidation();
    }

    /**
     * What the lines being typed add up to, and how far that is from the base of the invoice.
     *
     * @return array{totals: list<?int>, sum: int, difference: ?int}
     */
    public function linesSummary(): array
    {
        $totals = array_map(function (array $row): ?int {
            $quantity = $this->number($row['quantity'] ?? '');
            $price = Money::toCents($this->number($row['unit_price'] ?? ''));

            return is_numeric($quantity) && (float) $quantity > 0 && $price !== null && $price >= 0
                ? InvoiceImportDraft::line('', $quantity, $price, null)['total']
                : null;
        }, $this->lines);
        $net = $this->currentImport()?->data['net'] ?? null;

        return ['totals' => $totals, 'sum' => array_sum($totals), 'difference' => $net === null ? null : array_sum($totals) - $net];
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
    public function saveByHand(ConfirmInvoiceImport $confirm, DecideInvoiceImport $decide): void
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
            'form.rate' => ['required', 'in:several,'.implode(',', array_keys(Money::vatOptions()))],
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

        // "Várias taxas…": the total is split by rate in "Corrigir", one row for each.
        $several = $data['rate'] === 'several';
        $rate = $several ? '0.2300' : $data['rate'];
        $net = Money::netFromGross((int) Money::toCents($this->number($data['total'])), $rate);
        $draft = InvoiceImportDraft::corrected([
            'type' => filled($data['number'] ?? null) ? FiscalDocumentType::Invoice->value : null,
            'due_date' => null, 'atcud' => null, 'customer_name' => null, 'customer_nif' => null, 'lines' => [], 'paid' => true, 'exact' => [],
        ], [
            'supplier_name' => trim($data['supplier_name']),
            'supplier_nif' => FiscalTerms::nif($data['supplier_nif'] ?? null),
            'number' => filled($data['number'] ?? null) ? trim($data['number']) : null,
            'date' => $data['date'],
            'rates' => [['rate' => $rate, 'net' => $net, 'vat' => Money::vat($net, $rate)]],
        ]);

        $import->update([
            'status' => InvoiceImportStatus::Read,
            'error' => null,
            'data' => $draft,
            'choices' => ['category_id' => filled($data['category_id']) && Category::query()->expense()->whereKey($data['category_id'])->exists() ? (int) $data['category_id'] : null],
        ]);

        $this->mode = 'confirm';
        $this->reset('form');

        if ($several) {
            $this->correct($decide);
            $this->addRate();

            return;
        }

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

    /**
     * The sales and the retainers a direct cost can belong to, the newest first.
     *
     * @return array<string, array<string, string>>
     */
    private function costCentres(): array
    {
        $label = fn (Project|Retainer $record): string => implode(' · ', array_filter([$record->code, $record->title]));

        return array_filter([
            __('Sales') => Project::query()->latest('id')->limit(100)->get()->mapWithKeys(fn (Project $project): array => ['project:'.$project->id => $label($project)])->all(),
            __('Retainers') => Retainer::query()->latest('id')->limit(100)->get()->mapWithKeys(fn (Retainer $retainer): array => ['retainer:'.$retainer->id => $label($retainer)])->all(),
        ]);
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

        foreach ($attributes['lines'] ?? [] as $index => $line) {
            foreach (['quantity', 'unit_price'] as $field) {
                $attributes['lines'][$index][$field] = $this->number($line[$field] ?? '');
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
