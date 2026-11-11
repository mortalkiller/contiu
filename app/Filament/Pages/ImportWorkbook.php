<?php

namespace App\Filament\Pages;

use App\Actions\ImportFinanceWorkbook;
use App\Actions\ImportRefusedException;
use App\Actions\ImportResult;
use App\Models\User;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * Upload the "controlo financeiro" workbook, preview what it would create (dry run), then import.
 */
class ImportWorkbook extends Page
{
    protected string $view = 'filament.pages.import-workbook';

    protected static ?string $slug = 'importar-excel';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::FileArrowUp;

    protected static ?int $navigationSort = 2;

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** Result of the last dry run, shown before importing. */
    #[Locked]
    public ?array $preview = null;

    /** The workbook this page stored on the last run: the only path its file field takes back. */
    #[Locked]
    public ?string $storedFile = null;

    /** Pedro's personal workbook format (and "replace" wipes the account): super admins only. */
    public static function canAccess(): bool
    {
        return User::loggedInIsSuperAdmin();
    }

    public function mount(): void
    {
        $this->form->fill(['replace' => false]);
    }

    public function getSubheading(): ?string
    {
        return __('Upload the financial tracking workbook (Receitas, Pagamentos, Despesas and Listas sheets). You can review what will be created before saving it.');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make()
                    ->schema([
                        FileUpload::make('file')
                            ->label(__('Excel file'))
                            ->disk('local')
                            ->directory('imports')
                            // A path in the field's state comes from the browser: never one it names itself.
                            ->preventFilePathTampering(allowFilePathUsing: fn (string $file): bool => $file === $this->storedFile)
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/vnd.ms-excel',
                                'application/octet-stream',
                            ])
                            ->maxSize(10240)
                            ->required()
                            ->afterStateUpdated(fn () => $this->preview = null),
                        Checkbox::make('replace')
                            ->label(__('Replace current data'))
                            ->helperText(__('Deletes all clients, jobs, retainers, payments received, expenses and suppliers before importing. This cannot be undone.'))
                            ->afterStateUpdated(fn () => $this->preview = null),
                        Actions::make([
                            Action::make('preview')
                                ->label(__('Preview'))
                                ->icon(Phosphor::Eye)
                                ->color('gray')
                                ->action(fn () => $this->preview()),
                            Action::make('import')
                                ->label(__('Import'))
                                ->icon(Phosphor::FileArrowUp)
                                ->visible(fn () => $this->preview !== null)
                                ->extraAttributes(fn (): array => [
                                    'x-show' => (($this->preview['replace'] ?? false) ? '$wire.data.replace' : '! $wire.data.replace')
                                        .' && Object.values($wire.data.file ?? {})[0] === $wire.storedFile',
                                ])
                                ->requiresConfirmation(fn () => (bool) ($this->data['replace'] ?? false))
                                ->modalHeading(__('Replace current data?'))
                                ->modalDescription(__('The current financial data will be deleted and replaced with the file contents.'))
                                ->color(fn () => ($this->data['replace'] ?? false) ? 'danger' : 'primary')
                                ->action(fn () => $this->import()),
                        ]),
                    ]),
            ]);
    }

    public function preview(): void
    {
        $result = $this->runImport(dryRun: true);

        $this->preview = $result === null ? null : [
            ...$result->toArray(),
            'replace' => (bool) ($this->data['replace'] ?? false),
        ];
    }

    public function import(): void
    {
        if ($this->preview === null
            || $this->preview['replace'] !== (bool) ($this->data['replace'] ?? false)
            || Arr::first(Arr::wrap($this->data['file'] ?? null)) !== $this->storedFile) {
            throw ValidationException::withMessages(['data.replace' => __('Preview the document again before confirming.')]);
        }

        $result = $this->runImport(dryRun: false);

        if ($result === null) {
            return;
        }

        Storage::disk('local')->delete($this->storedPath());
        $this->storedFile = null;

        Notification::make()
            ->title(__('Import complete'))
            ->body(__(':projects jobs, :retainers retainers, :payments payments received and :expenses expenses. Contracted :contracted, received :received.', [
                'projects' => $result->counts['projects'],
                'retainers' => $result->counts['retainers'],
                'payments' => $result->counts['payments'],
                'expenses' => $result->counts['expenses'],
                'contracted' => Money::format($result->contracted, locale: app()->getLocale()),
                'received' => Money::format($result->received, locale: app()->getLocale()),
            ]))
            ->success()
            ->send();

        $this->preview = null;
        $this->form->fill(['replace' => false]);
    }

    /** Labels and values for the preview table. */
    public function previewRows(): array
    {
        return collect(ImportResult::LABELS)
            ->map(fn (string $label, string $key) => ['label' => __($label), 'value' => $this->preview['counts'][$key] ?? 0])
            ->values()
            ->all();
    }

    private function runImport(bool $dryRun): ?ImportResult
    {
        $data = $this->form->getState();
        $this->storedFile = $data['file'];

        try {
            return app(ImportFinanceWorkbook::class)->run(
                Storage::disk('local')->path($data['file']),
                replace: (bool) ($data['replace'] ?? false),
                dryRun: $dryRun,
            );
        } catch (ImportRefusedException $exception) {
            $this->preview = null;

            Notification::make()
                ->title(__('Unable to import'))
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return null;
        }
    }

    private function storedPath(): string
    {
        $file = $this->data['file'] ?? null;

        return is_array($file) ? (string) collect($file)->first() : (string) $file;
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return __('Import Excel');
    }

    #[Unexposed]
    public static function getNavigationGroup(): string
    {
        return __('Settings');
    }

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('Import Excel');
    }
}
