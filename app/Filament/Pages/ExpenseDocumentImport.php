<?php

namespace App\Filament\Pages;

use App\Actions\ImportExpenseDocuments;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Expense;
use App\Models\FiscalDocument;
use App\Support\CurrentAccount;
use App\Support\ExpenseDocumentCsv;
use App\Support\ExpenseDocumentQr;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Components\Attributes\Unexposed;
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

    public string $source = '';

    public string $method = 'csv';

    public string $qrText = '';

    public mixed $qrImage = null;

    public mixed $file = null;

    #[Locked]
    public ?string $previewSource = null;

    #[Locked]
    public ?int $previewAccountId = null;

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
        return ['summary' => $this->previewSource === null ? [] : $this->summary()];
    }

    /**
     * The invoices in the preview, counted by how well each one matches an expense already recorded.
     *
     * @return list<array{label: string, value: string, note?: ?string, tone?: ?string}>
     */
    private function summary(): array
    {
        $new = array_filter($this->candidates, fn (array $candidate): bool => $candidate['state'] === 'new');
        $matches = array_count_values(array_column($new, 'expense_match'));
        $toConfirm = ($matches['ambiguous'] ?? 0) + ($matches['limited'] ?? 0);
        $known = count($this->candidates) - count($new);

        return [
            ['label' => __('Invoices read'), 'value' => (string) count($this->rows), 'note' => $known > 0
                ? trans_choice('{1} 1 already imported or conflicting|[2,*] :count already imported or conflicting', $known)
                : __('Source :source', ['source' => $this->previewSource])],
            ['label' => __('Exact match'), 'value' => (string) ($matches['exact'] ?? 0), 'note' => __('Same date, amounts and number'), 'tone' => 'success'],
            ['label' => __('Awaiting confirmation'), 'value' => (string) $toConfirm, 'note' => $toConfirm > 0 ? __('Several possible expenses') : __('None found'), 'tone' => $toConfirm > 0 ? 'warning' : null],
            ['label' => __('No candidates'), 'value' => (string) ($matches['none'] ?? 0), 'note' => __('Create the expense from the invoice')],
        ];
    }

    public static function canConfirm(): bool
    {
        return static::canAccess()
            && ! CurrentAccount::readOnly()
            && Gate::allows('create', FiscalDocument::class)
            && Gate::allows('update', new Expense);
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

    public function preview(ExpenseDocumentCsv $parser, ImportExpenseDocuments $import): void
    {
        abort_unless(static::canAccess(), 403);
        $this->clearPreview();
        $this->resetValidation();

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

        $this->setPreview(trim($data['source']), $rows, $candidates);
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
        $this->setPreview($source, [$row], $import->preview($source, [$row]));
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
            $created = $import->confirm($this->previewSource, $this->rows, $choices);
            $this->candidates = $import->preview($this->previewSource, $this->rows);
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

    private function clearPreview(): void
    {
        $this->reset('previewSource', 'previewAccountId', 'rows', 'candidates', 'choices');
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
