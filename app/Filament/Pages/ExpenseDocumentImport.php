<?php

namespace App\Filament\Pages;

use App\Actions\ImportExpenseDocuments;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Expense;
use App\Models\FiscalDocument;
use App\Support\CurrentAccount;
use App\Support\EFaturaCsv;
use App\Support\ExpenseDocumentCsv;
use App\Support\ExpenseDocumentQr;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class ExpenseDocumentImport extends Page
{
    use WithFileUploads;

    protected static ?string $slug = 'importar-faturas';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::UploadSimple;

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.expense-document-import';

    /** "Origem", as in the mock: the e-Fatura export (every supplier at once) or a CSV of one supplier. */
    public string $origin = 'efatura';

    public string $source = '';

    public string $method = 'csv';

    public string $qrText = '';

    public mixed $qrImage = null;

    public mixed $file = null;

    #[Locked]
    public ?string $previewSource = null;

    /** Where the preview came from: efatura, csv or qr. */
    #[Locked]
    public ?string $previewOrigin = null;

    /** Documents in the e-Fatura file that are not read (credit notes, receipts, cancelled). */
    #[Locked]
    public int $previewSkipped = 0;

    #[Locked]
    public ?int $previewAccountId = null;

    /** The CSV's own name, shown on the first summary card. */
    #[Locked]
    public ?string $previewFileName = null;

    /** The supplier the source belongs to, for the "Supplier" column. */
    #[Locked]
    public ?string $previewSupplier = null;

    #[Locked]
    public array $rows = [];

    #[Locked]
    public array $candidates = [];

    public array $choices = [];

    public static function canAccess(): bool
    {
        return CurrentAccount::get() !== null && Gate::allows('viewAny', Expense::class);
    }

    public function getSubheading(): ?string
    {
        return __('Attach received invoices to expenses without entering the details manually.');
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
        return [
            // Shown before anything is read too, at zero, so the screen keeps the mock's shape.
            'summary' => $this->summary(),
            'chosenCount' => $this->chosenCount(),
        ];
    }

    /**
     * The invoices in the preview, counted by how well each one matches an expense already
     * recorded. As in the mock, the figures that need a look are in colour and the first card
     * names the file read.
     *
     * @return list<array{label: string, value: string, note?: ?string, valueTone?: ?string}>
     */
    private function summary(): array
    {
        $new = array_filter($this->candidates, fn (array $candidate): bool => $candidate['state'] === 'new');
        $matches = array_count_values(array_column($new, 'expense_match'));
        $toConfirm = ($matches['ambiguous'] ?? 0) + ($matches['limited'] ?? 0);
        $known = count($this->candidates) - count($new);

        return [
            ['label' => __('Invoices read'), 'value' => (string) count($this->rows), 'note' => match (true) {
                $known > 0 => trans_choice('{1} 1 already imported or conflicting|[2,*] :count already imported or conflicting', $known),
                $this->previewSkipped > 0 => trans_choice('{1} 1 other document left out|[2,*] :count other documents left out', $this->previewSkipped),
                $this->previewSource === null => __('No file read yet'),
                default => $this->previewFileName ?? __('Source :source', ['source' => $this->previewSource]),
            }],
            ['label' => __('Exact match'), 'value' => (string) ($matches['exact'] ?? 0), 'valueTone' => ($matches['exact'] ?? 0) > 0 ? 'success' : null],
            ['label' => __('Awaiting confirmation'), 'value' => (string) $toConfirm, 'valueTone' => $toConfirm > 0 ? 'warning' : null],
            ['label' => __('No candidates'), 'value' => (string) ($matches['none'] ?? 0), 'note' => __('Create the expense from the invoice')],
        ];
    }

    /** How many invoices the "Import" button would save: those with an expense chosen. */
    private function chosenCount(): int
    {
        return count(array_filter($this->choices, fn (mixed $choice): bool => is_array($choice) && filled($choice['expense'] ?? null)));
    }

    public static function canConfirm(): bool
    {
        return static::canAccess()
            && ! CurrentAccount::readOnly()
            && Gate::allows('create', FiscalDocument::class)
            && Gate::allows('update', new Expense);
    }

    public function updatedOrigin(): void
    {
        $this->clearPreview();
        $this->resetValidation();
    }

    public function updatedSource(): void
    {
        $this->clearPreview();
    }

    public function updatedFile(): void
    {
        $this->clearPreview();
    }

    public function updatedMethod(): void
    {
        $this->clearPreview();
        $this->resetValidation();
    }

    public function updatedQrText(): void
    {
        $this->clearPreview();
    }

    public function updatedQrImage(): void
    {
        $this->clearPreview();
    }

    public function preview(ExpenseDocumentCsv $parser, ImportExpenseDocuments $import, EFaturaCsv $efatura): void
    {
        abort_unless(static::canAccess(), 403);
        $this->clearPreview();
        $this->resetValidation();

        if ($this->origin === 'efatura') {
            $this->previewEFatura($efatura, $import);

            return;
        }

        $data = $this->validate([
            'source' => ['required', 'string', 'max:120'],
            'file' => ['required', 'file', 'extensions:csv', 'mimes:csv,txt', 'max:'.ExpenseDocumentCsv::MAX_KILOBYTES],
        ], [
            'source.required' => __('Enter the source of the invoices.'),
            'source.max' => __('The source can have up to 120 characters.'),
            'file.required' => __('Choose a CSV file.'),
            'file.file' => __('Choose a valid CSV file.'),
            'file.extensions' => __('Choose a file with the .csv extension.'),
            'file.mimes' => __('Choose a CSV file.'),
            'file.max' => __('The CSV file can be up to 256 KB.'),
        ]);

        try {
            $rows = $parser->parse($data['file']->get());
            $candidates = $import->preview($data['source'], $rows);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        $this->method = 'csv';
        $this->setPreview(trim($data['source']), $rows, $candidates);
        $this->previewOrigin = 'csv';
        $this->previewFileName = $data['file']->getClientOriginalName();
        $this->previewSupplier = $import->supplierFor(trim($data['source']))?->name;
    }

    /** The e-Fatura export: every supplier in one file, each invoice matched under its issuer's tax ID. */
    private function previewEFatura(EFaturaCsv $parser, ImportExpenseDocuments $import): void
    {
        $file = $this->validate([
            'file' => ['required', 'file', 'extensions:csv', 'mimes:csv,txt', 'max:'.EFaturaCsv::MAX_KILOBYTES],
        ], [
            'file.required' => __('Choose the CSV file exported from e-Fatura.'),
            'file.file' => __('Choose a valid CSV file.'),
            'file.extensions' => __('Choose a file with the .csv extension.'),
            'file.mimes' => __('Choose a CSV file.'),
            'file.max' => __('The e-Fatura file can be up to 1 MB.'),
        ])['file'];

        try {
            ['rows' => $rows, 'skipped' => $skipped] = $parser->parse($file->get());
            // The export gives only the issuer's tax ID: the supplier's name comes from the suppliers on record.
            $rows = array_map(fn (array $row): array => [...$row, 'party_name' => $row['party_name'] ?? $import->supplierFor($row['party_tax_id'])?->name], $rows);
            $candidates = $this->previewBySupplier($rows, $import);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        $this->method = 'csv';
        $this->setPreview('e-Fatura', $rows, $candidates);
        $this->previewOrigin = 'efatura';
        $this->previewSkipped = $skipped;
        $this->previewFileName = $file->getClientOriginalName();
    }

    /**
     * The preview of rows from several suppliers: each supplier's invoices under its own tax ID
     * (the import's source), put back in the file's order.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function previewBySupplier(array $rows, ImportExpenseDocuments $import): array
    {
        $candidates = [];

        foreach ($this->rowsBySupplier($rows) as $taxId => $indexes) {
            $preview = $import->preview($taxId, array_map(fn (int $index): array => $rows[$index], $indexes));

            foreach ($indexes as $position => $index) {
                $candidates[$index] = $preview[$position];
            }
        }

        ksort($candidates);

        return array_values($candidates);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, list<int>> row indexes by the issuer's tax ID
     */
    private function rowsBySupplier(array $rows): array
    {
        $groups = [];

        foreach ($rows as $index => $row) {
            $groups[(string) $row['party_tax_id']][] = $index;
        }

        return $groups;
    }

    public function previewQr(ExpenseDocumentQr $parser, ImportExpenseDocuments $import): void
    {
        abort_unless(static::canAccess(), 403);
        $this->clearPreview();
        $this->resetValidation();
        $text = $this->validate(['qrText' => ['required', 'string', 'max:'.ExpenseDocumentQr::MAX_LENGTH]], [
            'qrText.required' => __('Paste the QR code contents.'),
            'qrText.max' => __('The QR code is too long.'),
        ])['qrText'];

        try {
            $this->setQrPreview($parser->parse($text), $import);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['qrText' => $exception->getMessage()]);
        }
    }

    public function previewQrImage(ExpenseDocumentQr $parser, ImportExpenseDocuments $import): void
    {
        abort_unless(static::canAccess(), 403);
        $this->clearPreview();
        $this->resetValidation();
        $photo = $this->validate(['qrImage' => ['required', 'file', 'mimes:jpeg,png,webp', 'max:'.ExpenseDocumentQr::MAX_IMAGE_KILOBYTES]], [
            'qrImage.required' => __('Take or choose a photo of the QR code.'),
            'qrImage.mimes' => __('Choose a JPEG, PNG or WebP photo.'),
            'qrImage.max' => __('The photo can be up to 8 MB.'),
        ])['qrImage'];

        try {
            $this->setQrPreview($parser->parse($parser->readImage($photo->get())), $import);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['qrImage' => $exception->getMessage()]);
        } finally {
            $photo->delete();
            $this->reset('qrImage');
        }
    }

    private function setQrPreview(array $row, ImportExpenseDocuments $import): void
    {
        $source = $row['party_tax_id'];
        $row['party_name'] = $import->supplierFor($source)?->name;
        $this->method = 'qr';
        $this->setPreview($source, [$row], $import->preview($source, [$row]));
        $this->previewOrigin = 'qr';
        $this->previewSupplier = $row['party_name'];
    }

    private function setPreview(string $source, array $rows, array $candidates): void
    {
        $this->previewAccountId = CurrentAccount::id();
        $this->previewSource = $source;
        $this->rows = $rows;
        $this->candidates = $candidates;
    }

    public function confirm(ImportExpenseDocuments $import): void
    {
        abort_unless(static::canConfirm(), 403);

        if ($this->previewAccountId === null || $this->previewSource === null) {
            throw ValidationException::withMessages([
                ($this->method === 'qr' ? 'qrText' : 'file') => __('Preview the document again before confirming.'),
            ]);
        }

        abort_unless($this->previewAccountId === CurrentAccount::id(), 403);
        $this->resetValidation('choices');

        $choices = $this->validate([
            'choices' => ['array'],
            'choices.*' => ['array:expense,bank'],
            'choices.*.expense' => ['nullable', 'string'],
            'choices.*.bank' => ['nullable', 'string'],
        ], ['choices.*.array' => __('Review your selections in the preview.')])['choices'];

        $choices = array_filter($choices, fn (array $choice): bool => filled($choice['expense'] ?? null) || filled($choice['bank'] ?? null));

        if ($choices === []) {
            throw ValidationException::withMessages(['choices' => __('Choose what to save for at least one invoice.')]);
        }

        foreach ($choices as $index => $choice) {
            $candidate = $this->candidates[$index] ?? null;
            $expense = $choice['expense'] ?? '';
            $bank = $choice['bank'] ?? '';

            if ($candidate === null || $candidate['state'] !== 'new'
                || ($expense !== 'unlinked' && ! isset($candidate['expenses'][$expense]))
                || ($bank !== '' && ($expense === 'unlinked' || ! isset($candidate['banks'][$bank])))) {
                throw ValidationException::withMessages(['choices' => __('Review the selected expense and transaction.')]);
            }
        }

        try {
            if ($this->previewOrigin === 'efatura') {
                $created = $this->confirmBySupplier($choices, $import);
                $this->candidates = $this->previewBySupplier($this->rows, $import);
            } else {
                $created = $import->confirm($this->previewSource, $this->rows, $choices);
                $this->candidates = $import->preview($this->previewSource, $this->rows);
            }
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['choices' => $exception->getMessage()]);
        }

        $this->choices = [];

        Notification::make()
            ->title(match ($created) {
                0 => __('The selected invoices were already imported.'),
                default => trans_choice('{1} :count invoice imported.|[2,*] :count invoices imported.', $created),
            })
            ->success()
            ->send();
    }

    /**
     * Confirms an e-Fatura preview one supplier at a time, all or nothing.
     *
     * @param  array<int, array{expense?: ?string, bank?: ?string}>  $choices
     */
    private function confirmBySupplier(array $choices, ImportExpenseDocuments $import): int
    {
        return DB::transaction(function () use ($choices, $import): int {
            $created = 0;

            foreach ($this->rowsBySupplier($this->rows) as $taxId => $indexes) {
                $chosen = array_values(array_filter($indexes, fn (int $index): bool => isset($choices[$index])));

                if ($chosen === []) {
                    continue;
                }

                $created += $import->confirm(
                    $taxId,
                    array_map(fn (int $index): array => $this->rows[$index], $chosen),
                    array_map(fn (int $index): array => $choices[$index], $chosen),
                );
            }

            return $created;
        });
    }

    private function clearPreview(): void
    {
        $this->reset('previewSource', 'previewOrigin', 'previewSkipped', 'previewAccountId', 'previewFileName', 'previewSupplier', 'rows', 'candidates', 'choices');
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
