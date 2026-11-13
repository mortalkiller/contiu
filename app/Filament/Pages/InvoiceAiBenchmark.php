<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Support\CurrentAccount;
use App\Support\InvoiceBenchmark\InvoiceBenchmarkDashboard;
use App\Support\InvoiceBenchmark\InvoiceBenchmarkFixtures;
use App\Support\InvoiceBenchmark\InvoiceBenchmarkService;
use App\Support\InvoiceBenchmark\InvoiceData;
use App\Support\InvoiceBenchmark\InvoiceQrCodeService;
use BackedEnum;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Fruitcake\LaravelDebugbar\Facades\Debugbar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class InvoiceAiBenchmark extends Page
{
    use WithFileUploads {
        _startUpload as private startTemporaryUpload;
    }

    protected string $view = 'filament.pages.invoice-ai-benchmark';

    protected static ?string $title = 'Invoice AI Benchmark';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::Receipt;

    public $upload = null;

    public string $fixture = 'standard-qr';

    public string $mode = 'qr';

    #[Locked]
    public ?string $documentId = null;

    #[Locked]
    public ?int $runId = null;

    public int $previewPage = 1;

    public array $correction = [];

    #[Locked]
    public bool $reviewLoaded = false;

    public function boot(): void
    {
        abort_unless(static::canAccess(), 403);

        Debugbar::disable();
    }

    public static function canAccess(): bool
    {
        return config('invoice-benchmark.enabled') && User::loggedInIsSuperAdmin();
    }

    public static function getNavigationGroup(): string
    {
        return __('Settings');
    }

    #[Renderless]
    public function _startUpload($name, $fileInfo, $isMultiple): void
    {
        $this->requirePrivateTemporaryDisk();
        abort_unless($name === 'upload' && ! $isMultiple, 422);
        $this->startTemporaryUpload($name, $fileInfo, $isMultiple);
    }

    public function uploadDocument(InvoiceBenchmarkService $benchmark): void
    {
        $this->requirePrivateTemporaryDisk();
        $this->validate(['upload' => ['required', 'file', 'mimetypes:'.implode(',', InvoiceQrCodeService::MIME_TYPES),
            'max:'.(config('invoice-benchmark.max_file_size_mb') * 1024)]]);
        abort_unless($this->upload instanceof TemporaryUploadedFile, 422);
        $this->limit('upload');

        try {
            $document = $benchmark->upload($this->upload, (int) auth()->id(), CurrentAccount::id());
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['upload' => $exception->getMessage()]);
        }
        $this->upload->delete();
        $this->reset('upload');
        $this->selectDocument($document->id, $benchmark);
    }

    public function loadDemo(InvoiceBenchmarkService $benchmark): void
    {
        $this->validate(['fixture' => ['required', Rule::in(array_keys(InvoiceBenchmarkFixtures::all()))]]);
        $this->limit('fixture');

        try {
            $document = $benchmark->upload(InvoiceBenchmarkFixtures::file($this->fixture), (int) auth()->id(), CurrentAccount::id(), $this->fixture);
            $this->selectDocument($document->id, $benchmark);
            $run = $benchmark->queue($document, 'demo');
            $this->openRun($run->id, $benchmark);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['fixture' => $exception->getMessage()]);
        }
    }

    public function selectDocument(string $id, InvoiceBenchmarkService $benchmark): void
    {
        $document = $this->query($benchmark)->findOrFail($id);
        $this->documentId = $document->id;
        $this->reset('runId', 'previewPage', 'correction', 'reviewLoaded');
    }

    public function processDocument(InvoiceBenchmarkService $benchmark): void
    {
        $this->validate(['mode' => ['required', Rule::in(['ai', 'qr_ai', 'qr'])]]);
        $this->limit('mode');

        try {
            $run = $benchmark->queue($this->query($benchmark)->findOrFail($this->documentId), $this->mode);
            $this->openRun($run->id, $benchmark);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['mode' => $exception->getMessage()]);
        }
    }

    public function openRun(int $id, InvoiceBenchmarkService $benchmark): void
    {
        $document = $this->query($benchmark)->findOrFail($this->documentId);
        $run = $document->runs()->findOrFail($id);
        $this->runId = $run->id;
        $this->reviewLoaded = in_array($run->status, ['succeeded', 'failed'], true);
        if ($this->reviewLoaded) {
            $this->reviewForm->fill($document->ground_truth ?? $run->result ?? []);
        }
    }

    public function loadReview(InvoiceBenchmarkService $benchmark): void
    {
        abort_if($this->runId === null, 404);
        $this->openRun($this->runId, $benchmark);
    }

    public function reviewForm(Schema $schema): Schema
    {
        return $schema->statePath('correction')->components([
            Section::make('Document and totals')->schema($this->fields(array_diff(InvoiceData::FIELDS, [
                'supplier_name', 'supplier_nif', 'supplier_address', 'supplier_country',
                'customer_name', 'customer_nif', 'customer_address', 'customer_country',
            ])))->columns(3),
            Section::make('Supplier and customer')->schema($this->fields([
                'supplier_name', 'supplier_nif', 'supplier_address', 'supplier_country',
                'customer_name', 'customer_nif', 'customer_address', 'customer_country',
            ]))->columns(2),
            Section::make('VAT breakdown')->schema([
                Repeater::make('vat')->hiddenLabel()->defaultItems(0)->schema($this->fields(InvoiceData::VAT_FIELDS))->columns(3),
            ]),
            Section::make('Line items')->schema([
                Repeater::make('lines')->hiddenLabel()->defaultItems(0)->schema($this->fields(InvoiceData::LINE_FIELDS))->columns(4),
            ]),
        ]);
    }

    public function saveGroundTruth(InvoiceBenchmarkService $benchmark): void
    {
        $document = $this->query($benchmark)->findOrFail($this->documentId);
        abort_unless($document->runs()->whereKey($this->runId)->whereIn('status', ['succeeded', 'failed'])->exists(), 404);
        $truth = InvoiceData::verifiedValues($this->reviewForm->getState());
        if ($truth === []) {
            throw ValidationException::withMessages(['correction' => 'Enter at least one verified value.']);
        }

        $document->update(['ground_truth' => $truth, 'verified_at' => now()]);
        Notification::make()->title('Verified ground truth saved')->success()->send();
    }

    public function useFixtureGroundTruth(InvoiceBenchmarkService $benchmark): void
    {
        $document = $this->query($benchmark)->findOrFail($this->documentId);
        abort_if($document->fixture === null, 404);

        $truth = InvoiceBenchmarkFixtures::expected($document->fixture);
        $document->update(['ground_truth' => $truth, 'verified_at' => now()]);
        $this->reviewForm->fill($truth);
        Notification::make()->title('Synthetic reference values saved')->success()->send();
    }

    protected function getViewData(): array
    {
        $benchmark = app(InvoiceBenchmarkService::class);
        $documents = $this->query($benchmark);
        $selected = $this->documentId === null ? null : (clone $documents)->with('runs')->find($this->documentId);
        $run = $selected?->runs->firstWhere('id', $this->runId);

        return [
            'documents' => (clone $documents)->latest()->limit(30)->get(),
            'selected' => $selected,
            'run' => $run,
            'requestMetrics' => $run === null ? collect() : DB::table('invoice_benchmark_requests')->where('run_id', $run->id)->orderBy('attempt')->get(),
            'stats' => app(InvoiceBenchmarkDashboard::class)->summarize($documents),
            'fixtures' => InvoiceBenchmarkFixtures::all(),
            'modes' => InvoiceBenchmarkService::MODES,
            'live' => (bool) config('invoice-benchmark.live'),
        ];
    }

    private function query(InvoiceBenchmarkService $benchmark): Builder
    {
        abort_unless(static::canAccess(), 403);

        return $benchmark->documents((int) auth()->id(), CurrentAccount::id());
    }

    private function fields(array $keys): array
    {
        return array_map(fn (string $key): TextInput => TextInput::make($key)->maxLength(500), $keys);
    }

    private function requirePrivateTemporaryDisk(): void
    {
        $disk = config('livewire.temporary_file_upload.disk') ?: config('filesystems.default');
        $settings = config("filesystems.disks.{$disk}");
        if ($disk !== 'local' || ($settings['driver'] ?? null) !== 'local'
            || ($settings['visibility'] ?? 'private') !== 'private'
            || ($settings['root'] ?? null) !== storage_path('app/private')) {
            throw ValidationException::withMessages(['upload' => 'Configure Livewire to use the private local disk for benchmark uploads.']);
        }
    }

    private function limit(string $field): void
    {
        $key = 'invoice-benchmark:'.$field.':'.auth()->id();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages([$field => 'Too many benchmark actions. Try again in one minute.']);
        }
        RateLimiter::hit($key, 60);
    }
}
