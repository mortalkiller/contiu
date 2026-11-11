<?php

namespace App\Filament\Pages;

use App\Actions\IntakeInvoiceEmail;
use App\Actions\ReviewInvoiceEmailAttachment;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Expense;
use App\Models\InvoiceEmailAttachment;
use App\Support\CurrentAccount;
use App\Support\ExpenseDocumentQr;
use App\Support\InvoiceEmailPdfQr;
use App\Support\Money;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceEmailInboxPage extends Page implements HasTable
{
    use InteractsWithTable;
    use WithFileUploads {
        _startUpload as private startFileUpload;
        _finishUpload as private finishFileUpload;
    }

    private const SAMPLE_FILENAME = 'invoice-email-sample.pdf';

    private const SAMPLE_QR = 'A:508025338*B:999999990*C:PT*D:FT*E:N*F:20261110*G:FT EMAIL/1*H:EMAIL001-1*I1:PT*I7:20.00*I8:4.60*N:4.60*O:24.60*Q:YhGV*R:9999';

    protected static ?string $slug = 'integracoes/faturas-por-email';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.invoice-email-inbox-page';

    #[Locked]
    public int $accountId;

    public ?TemporaryUploadedFile $pdf = null;

    public string $qrText = '';

    public bool $scanPdf = false;

    private array $reviewPreviews = [];

    #[Unexposed]
    public static function canAccess(): bool
    {
        return ReviewInvoiceEmailAttachment::canReview();
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return __('Invoice email inbox');
    }

    public function mount(): void
    {
        $this->accountId = CurrentAccount::id();
    }

    public function hydrate(): void
    {
        abort_unless($this->accountId === CurrentAccount::id(), 403);
    }

    public function table(Table $table): Table
    {
        abort_unless(static::canAccess(), 403);

        return $table
            ->query(InvoiceEmailAttachment::query()->with('expense'))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('filename')->label(__('PDF'))->searchable(),
                TextColumn::make('created_at')->label(__('Received at'))->dateTime()->sortable(),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->state(fn (InvoiceEmailAttachment $record): string => $record->expense_id === null ? __('Pending review') : __('Attached'))
                    ->color(fn (InvoiceEmailAttachment $record): string => $record->expense_id === null ? 'warning' : 'success'),
                TextColumn::make('expense.description')->label(__('Expense'))->placeholder('—'),
            ])
            ->emptyStateHeading(__('No invoices in the inbox'))
            ->recordActions([
                Action::make('download')->label(__('Download PDF'))
                    ->action(fn (InvoiceEmailAttachment $record): StreamedResponse => $this->download($record)),
                $this->reviewAction(),
            ]);
    }

    public function loadSample(IntakeInvoiceEmail $intake): void
    {
        abort_unless(static::canAccess() && IntakeInvoiceEmail::canSimulate(), 403);
        $this->resetValidation();

        $this->receive($intake, 'sample-invoice-v1', self::SAMPLE_FILENAME, file_get_contents(resource_path('fixtures/'.self::SAMPLE_FILENAME)), self::SAMPLE_QR);
    }

    public function rotateAlias(IntakeInvoiceEmail $intake): void
    {
        abort_unless(static::canAccess(), 403);
        $intake->recipient(rotate: true);
    }

    #[Renderless]
    public function _startUpload($name, $fileInfo, $isMultiple): void
    {
        abort_unless($this->canUpload() && $name === 'pdf' && ! $isMultiple, 403);
        $this->startFileUpload($name, $fileInfo, $isMultiple);
    }

    public function _finishUpload($name, $tmpPath, $isMultiple, $append = true): void
    {
        abort_unless($this->canUpload() && $name === 'pdf' && ! $isMultiple && count($tmpPath) === 1, 403);
        $this->finishFileUpload($name, $tmpPath, $isMultiple, $append);
    }

    public function simulate(IntakeInvoiceEmail $intake, ExpenseDocumentQr $qr, InvoiceEmailPdfQr $reader): void
    {
        abort_unless($this->canUpload(), 403);

        $this->validate([
            'pdf' => ['required', 'file', 'mimetypes:application/pdf', 'max:'.IntakeInvoiceEmail::MAX_PDF_KILOBYTES],
            'qrText' => ['string', 'max:'.ExpenseDocumentQr::MAX_LENGTH],
        ], [
            'pdf.required' => __('Select a PDF file.'),
            'pdf.mimetypes' => __('Select a PDF file.'),
            'pdf.max' => __('Each simulated attachment can be up to 10 MiB.'),
            'qrText.max' => __('The QR code is too long.'),
        ]);

        $text = trim($this->qrText);
        if ($text !== '') {
            try {
                $qr->parse($text);
            } catch (DomainException $exception) {
                throw ValidationException::withMessages(['qrText' => $exception->getMessage()]);
            }
        }

        $bytes = $this->pdf->get();
        $text = $text !== '' ? $text : ($this->scanPdf ? $reader->read($bytes) : null);
        $this->receive($intake, Str::uuid()->toString(), $this->pdf->getClientOriginalName(), $bytes, $text);

        $this->pdf->delete();
        $this->reset('pdf', 'qrText', 'scanPdf');
    }

    protected function getViewData(): array
    {
        $canSimulate = static::canAccess() && IntakeInvoiceEmail::canSimulate();

        return [
            'canSimulate' => $canSimulate,
            'canUpload' => $this->canUpload(),
            'recipient' => $canSimulate ? app(IntakeInvoiceEmail::class)->currentRecipient() : null,
            'canScanPdf' => $canSimulate && app(InvoiceEmailPdfQr::class)->available(),
        ];
    }

    private function canUpload(): bool
    {
        $disk = config('livewire.temporary_file_upload.disk') ?: config('filesystems.default');

        return static::canAccess() && IntakeInvoiceEmail::canSimulate() && config("filesystems.disks.{$disk}.driver") === 'local';
    }

    private function receive(IntakeInvoiceEmail $intake, string $message, string $filename, string $bytes, ?string $qr): void
    {
        try {
            $count = $intake->receive($message, [$intake->recipient()], [
                ['name' => $filename, 'bytes' => $bytes, 'qr' => $qr],
            ]);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['pdf' => $exception->getMessage()]);
        }

        Notification::make()
            ->title($count === 0 ? __('This PDF is already in the inbox.') : __('PDF added to the inbox.'))
            ->success()
            ->send();
    }

    private function download(InvoiceEmailAttachment $record): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);
        abort_unless((int) $record->account_id === CurrentAccount::id(), 404);
        $disk = Storage::disk(InvoiceEmailAttachment::DISK);
        abort_unless($disk->exists($record->storagePath()), 404);

        return $disk->download($record->storagePath(), $record->filename, [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function reviewAction(): Action
    {
        return Action::make('review')
            ->label(__('Review'))
            ->visible(fn (InvoiceEmailAttachment $record): bool => $record->expense_id === null)
            ->modal()
            ->modalHeading(__('Review invoice PDF'))
            ->modalDescription(function (InvoiceEmailAttachment $record): string {
                $preview = $this->reviewPreview($record);
                $row = $preview['row'];

                return match ($preview['state']) {
                    'manual' => __('No readable fiscal QR code. Check the PDF and choose an expense manually.'),
                    'possible_duplicate' => __('Possible duplicate. Check the PDF and choose an expense manually. Only the PDF will be attached.'),
                    'conflict' => __('This reference has already been imported with different details or links.'),
                    default => implode(' · ', array_filter([
                        $row['party_name'] ?? $row['party_tax_id'],
                        $row['number'],
                        $row['document_date'],
                        Money::format($row['total_amount']),
                        match (true) {
                            $preview['state'] === 'matched' => __('This invoice is already linked to an expense.'),
                            $preview['options'] === [] => __('No matching expenses. Create an expense, then reopen this review to attach the PDF.'),
                            default => null,
                        },
                    ])),
                };
            })
            ->modalSubmitAction(function (Action $action, InvoiceEmailAttachment $record): Action|false {
                $preview = $this->reviewPreview($record);

                return ReviewInvoiceEmailAttachment::canAttach() && ($this->requiresManualExpense($preview) || $preview['options'] !== [])
                    ? $action
                    : false;
            })
            ->modalSubmitActionLabel(__('Attach PDF'))
            ->extraModalFooterActions(function (InvoiceEmailAttachment $record): array {
                $preview = $this->reviewPreview($record);
                if ($preview['options'] !== [] || ! in_array($preview['state'], ['manual', 'qr'], true) || ! ReviewInvoiceEmailAttachment::canAttach()
                    || ! ExpenseResource::canCreate()) {
                    return [];
                }

                return [
                    Action::make('createExpense')->label(__('Create expense'))->color('gray')
                        ->url(ExpenseResource::getUrl('create'), shouldOpenInNewTab: true),
                ];
            })
            ->fillForm(fn (InvoiceEmailAttachment $record): array => [
                'expense' => $this->reviewPreview($record)['suggested'],
            ])
            ->schema(fn (InvoiceEmailAttachment $record): array => $this->reviewFields($record))
            ->action(function (InvoiceEmailAttachment $record, array $data): void {
                try {
                    app(ReviewInvoiceEmailAttachment::class)->attach($record->id, (int) ($data['expense'] ?? 0));
                } catch (DomainException $exception) {
                    throw ValidationException::withMessages([$this->getMountedActionSchema()->getStatePath().'.expense' => $exception->getMessage()]);
                }

                Notification::make()->title(__('PDF attached to the expense.'))->success()->send();
            });
    }

    /** @return list<Select> */
    private function reviewFields(InvoiceEmailAttachment $record): array
    {
        $preview = $this->reviewPreview($record);
        if (! $this->requiresManualExpense($preview) && $preview['options'] === []) {
            return [];
        }

        $select = Select::make('expense')->label(__('Expense'))->required()->searchable()
            ->disabled(! ReviewInvoiceEmailAttachment::canAttach());

        if ($this->requiresManualExpense($preview)) {
            $select
                ->getSearchResultsUsing(fn (string $search): array => Expense::query()
                    ->where(fn ($query) => $query->whereLike('description', '%'.$search.'%')->orWhereLike('invoice_number', '%'.$search.'%'))
                    ->latest('id')->limit(20)->get()
                    ->mapWithKeys(fn (Expense $expense): array => [$expense->id => ReviewInvoiceEmailAttachment::expenseLabel($expense)])->all())
                ->getOptionLabelUsing(function (string $value): ?string {
                    $expense = ctype_digit($value) ? Expense::query()->find($value) : null;

                    return $expense === null ? null : ReviewInvoiceEmailAttachment::expenseLabel($expense);
                });
        } else {
            $select->options($preview['options']);
        }

        return [$select];
    }

    private function reviewPreview(InvoiceEmailAttachment $record): array
    {
        return $this->reviewPreviews[$record->id] ??= app(ReviewInvoiceEmailAttachment::class)->preview($record);
    }

    private function requiresManualExpense(array $preview): bool
    {
        return in_array($preview['state'], ['manual', 'possible_duplicate'], true);
    }
}
