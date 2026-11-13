<?php

namespace App\Modules\InvoiceReader\Benchmark\Filament;

use App\Models\User;
use App\Modules\InvoiceReader\Benchmark\InvoiceBenchmarkDashboard;
use App\Modules\InvoiceReader\Benchmark\InvoiceBenchmarkFixtures;
use App\Modules\InvoiceReader\Benchmark\InvoiceBenchmarkService;
use App\Modules\InvoiceReader\Benchmark\Models\InvoiceBenchmarkDocument;
use App\Modules\InvoiceReader\GeoApiPostalLookup;
use App\Modules\InvoiceReader\InvoiceData;
use App\Modules\InvoiceReader\InvoiceQrCodeService;
use App\Modules\InvoiceReader\ViesSupplierLookup;
use App\Support\CurrentAccount;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Support\Enums\Width;
use Fruitcake\LaravelDebugbar\Facades\Debugbar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
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

    protected string $view = 'invoice-reader::invoice-ai-benchmark';

    protected Width|string|null $maxContentWidth = Width::Full;

    protected static bool $isDiscovered = false;

    protected static string|BackedEnum|null $navigationIcon = Phosphor::Receipt;

    public $upload = null;

    public string $fixture = 'poor-photo';

    public string $mode = 'qr';

    #[Locked]
    public string $activeView = 'demo';

    #[Locked]
    public ?string $documentId = null;

    #[Locked]
    public ?int $runId = null;

    public int $previewPage = 1;

    public array $correction = [];

    #[Locked]
    public array $postalSuggestions = [];

    #[Locked]
    public bool $reviewLoaded = false;

    public function boot(): void
    {
        abort_unless(static::canAccess(), 403);

        Debugbar::disable();
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return config('invoice-benchmark.enabled') && $user instanceof User
            && $user->isSuperAdmin() && $user->hasVerifiedEmail();
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('Invoice AI Benchmark');
    }

    public function openView(string $view): void
    {
        abort_unless(in_array($view, ['demo', 'analyze', 'history'], true), 404);

        if ($this->activeView === $view) {
            return;
        }

        $this->activeView = $view;
        $this->reset('documentId', 'runId', 'previewPage', 'correction', 'postalSuggestions', 'reviewLoaded', 'mode', 'upload');
        $this->resetValidation();
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
        $this->setDocument($this->storeUpload($benchmark));
    }

    private function storeUpload(InvoiceBenchmarkService $benchmark): InvoiceBenchmarkDocument
    {
        $this->requirePrivateTemporaryDisk();
        $this->validate(['upload' => ['required', 'file', 'mimetypes:'.implode(',', InvoiceQrCodeService::MIME_TYPES),
            'max:'.(config('invoice-benchmark.max_file_size_mb') * 1024)]], [
                'upload.required' => __('Choose a photo or PDF.'),
                'upload.file' => __('Choose a valid file.'),
                'upload.mimetypes' => __('Choose a JPEG, PNG, WebP or PDF file.'),
                'upload.max' => __('Choose a file up to :size MB.', ['size' => config('invoice-benchmark.max_file_size_mb')]),
                'upload.uploaded' => __('The file could not be uploaded.'),
            ]);
        abort_unless($this->upload instanceof TemporaryUploadedFile, 422);
        $this->limit('upload');

        try {
            $document = $benchmark->upload($this->upload, (int) auth()->id(), CurrentAccount::id());
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['upload' => __($exception->getMessage())]);
        }
        $this->upload->delete();
        $this->reset('upload');

        return $document;
    }

    public function loadDemo(InvoiceBenchmarkService $benchmark): void
    {
        $this->validate(['fixture' => ['required', Rule::in(array_keys(InvoiceBenchmarkFixtures::all()))]], [
            'fixture.required' => __('Choose a sample invoice.'),
            'fixture.in' => __('Choose an available sample invoice.'),
        ]);
        $this->limit('fixture');

        try {
            $document = $benchmark->upload(InvoiceBenchmarkFixtures::file($this->fixture), (int) auth()->id(), CurrentAccount::id(), $this->fixture);
            $this->setDocument($document);
            $run = $benchmark->queue($document, 'demo');
            $this->openRun($run->id, $benchmark);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['fixture' => __($exception->getMessage())]);
        }
    }

    public function selectDocument(string $id, InvoiceBenchmarkService $benchmark): void
    {
        $document = $this->query($benchmark)->findOrFail($id);
        $this->setDocument($document);
        $latestRun = $document->runs()->latest('id')->first();
        if ($latestRun !== null) {
            $this->openRun($latestRun->id, $benchmark);
        }
    }

    public function analyzeSelected(InvoiceBenchmarkService $benchmark): void
    {
        $document = $this->query($benchmark)->findOrFail($this->documentId);
        $this->openView('analyze');
        $this->setDocument($document);
    }

    private function setDocument(InvoiceBenchmarkDocument $document): void
    {
        $this->documentId = $document->id;
        $this->reset('runId', 'previewPage', 'correction', 'postalSuggestions', 'reviewLoaded');
    }

    public function processDocument(InvoiceBenchmarkService $benchmark): void
    {
        $this->validate(['mode' => ['required', Rule::in(['ai', 'qr_ai', 'qr'])]], [
            'mode.required' => __('Choose an analysis method.'),
            'mode.in' => __('Choose an available analysis method.'),
        ]);
        if ($this->mode !== 'qr' && ! config('invoice-benchmark.live')) {
            throw ValidationException::withMessages(['mode' => __('Paid AI methods are currently unavailable.')]);
        }
        $this->limit('mode');

        if ($this->upload !== null || $this->documentId === null) {
            $this->uploadDocument($benchmark);
        }

        try {
            $run = $benchmark->queue($this->query($benchmark)->findOrFail($this->documentId), $this->mode);
            $this->openRun($run->id, $benchmark);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['mode' => __($exception->getMessage())]);
        }
    }

    public function openRun(int $id, InvoiceBenchmarkService $benchmark): void
    {
        $document = $this->query($benchmark)->findOrFail($this->documentId);
        $run = $document->runs()->findOrFail($id);
        $this->runId = $run->id;
        $this->postalSuggestions = [];
        $this->reviewLoaded = in_array($run->status, ['succeeded', 'failed'], true);
        if ($this->reviewLoaded) {
            $extracted = InvoiceData::withQrVatRates($run->result ?? [], $run->qr_output['data'] ?? []);
            $supplier = $run->review['vies_supplier'] ?? [];
            $customer = $run->review['vies_customer'] ?? [];
            $supplierAddress = ViesSupplierLookup::addressParts($supplier['supplier_address'] ?? '', 'supplier');
            $customerAddress = ViesSupplierLookup::addressParts($customer['customer_address'] ?? '', 'customer');
            if (InvoiceData::normalizeNif($extracted['customer_nif'] ?? null) === InvoiceData::FINAL_CONSUMER_NIF) {
                $extracted['customer_name'] = __('Final consumer');
            }
            $correction = $document->ground_truth ?? array_replace($extracted, $supplier, $supplierAddress, $customer, $customerAddress);
            if (blank($correction['customer_name'] ?? null)
                && InvoiceData::normalizeNif($correction['customer_nif'] ?? null) === InvoiceData::FINAL_CONSUMER_NIF) {
                $correction['customer_name'] = __('Final consumer');
            }
            $this->fillCorrection($correction);
            if ($document->ground_truth === null) {
                $this->suggestPostalAddress($supplierAddress, 'supplier');
                $this->suggestPostalAddress($customerAddress, 'customer');
            }
        }
    }

    private function suggestPostalAddress(array $addressParts, string $party): void
    {
        $postalCode = $addressParts[$party.'_postal_code'] ?? null;
        $needsStreet = ! isset($addressParts[$party.'_street']);
        if ($postalCode === null || (! $needsStreet && isset($addressParts[$party.'_locality']))) {
            return;
        }

        $result = app(GeoApiPostalLookup::class)->lookup($postalCode);
        if ($result === null) {
            return;
        }

        $streets = $needsStreet ? $result['streets'] : [];
        if (count($streets) === 1) {
            $this->correction[$party.'_street'] = $streets[0];
        }

        $this->postalSuggestions[$party] = [
            'postal_code' => $postalCode,
            'locality' => $result['locality'],
            'streets' => count($streets) > 1 ? $streets : [],
        ];

        if (blank($this->correction[$party.'_locality'])) {
            $this->correction[$party.'_locality'] = $result['locality'];
        }
    }

    public function choosePostalStreet(string $index, string $party = 'supplier'): void
    {
        if ($index === '') {
            return;
        }

        $suggestions = $this->postalSuggestionsFor($party);
        abort_unless(isset($suggestions['streets'][$index]), 422);

        $this->correction[$party.'_street'] = $suggestions['streets'][$index];
    }

    public function usePostalLocality(string $party = 'supplier'): void
    {
        $suggestions = $this->postalSuggestionsFor($party);
        abort_unless(isset($suggestions['locality']), 422);

        $this->correction[$party.'_locality'] = $suggestions['locality'];
    }

    private function postalSuggestionsFor(string $party): array
    {
        $suggestions = $this->postalSuggestions[$party] ?? null;
        abort_unless($this->reviewLoaded && $suggestions !== null
            && $suggestions['postal_code'] === ($this->correction[$party.'_postal_code'] ?? null), 422);

        return $suggestions;
    }

    public function loadReview(InvoiceBenchmarkService $benchmark): void
    {
        abort_if($this->runId === null, 404);
        $this->openRun($this->runId, $benchmark);
    }

    public function refreshAnalysis(InvoiceBenchmarkService $benchmark): void
    {
        if ($this->runId === null || $this->reviewLoaded) {
            return;
        }

        $document = $this->query($benchmark)->find($this->documentId);
        $run = $document?->runs()->whereIn('status', ['succeeded', 'failed'])->find($this->runId);
        if ($run !== null) {
            $this->openRun($run->id, $benchmark);
        }
    }

    private function fillCorrection(array $data): void
    {
        $this->resetValidation();
        $fields = [...InvoiceData::FIELDS, ...InvoiceData::ADDRESS_FIELDS['supplier'], ...InvoiceData::ADDRESS_FIELDS['customer']];
        $this->correction = array_replace(array_fill_keys($fields, null), $data);

        foreach (['lines', 'vat'] as $list) {
            $this->correction[$list] = [];
            foreach ($data[$list] ?? [] as $row) {
                $this->correction[$list][(string) Str::uuid()] = array_replace($this->emptyCorrectionRow($list), $row);
            }
        }
    }

    public function addCorrectionRow(string $list): void
    {
        $this->authorizeCorrectionRows($list);
        if (count($this->correction[$list] ?? []) >= 200) {
            return;
        }

        $this->correction[$list][(string) Str::uuid()] = $this->emptyCorrectionRow($list);
    }

    public function removeCorrectionRow(string $list, string $key): void
    {
        $this->authorizeCorrectionRows($list);
        unset($this->correction[$list][$key]);
        $this->resetValidation();
    }

    public function moveCorrectionRow(string $list, string $key, int $direction): void
    {
        $this->authorizeCorrectionRows($list);
        abort_unless(in_array($direction, [-1, 1], true), 404);

        $rows = $this->correction[$list] ?? [];
        $keys = array_keys($rows);
        $position = array_search($key, $keys, true);
        if ($position === false || ! isset($keys[$position + $direction])) {
            return;
        }

        $target = $position + $direction;
        [$keys[$position], $keys[$target]] = [$keys[$target], $keys[$position]];
        $this->correction[$list] = array_replace(array_fill_keys($keys, null), $rows);
    }

    private function authorizeCorrectionRows(string $list): void
    {
        abort_unless($this->reviewLoaded && in_array($list, ['lines', 'vat'], true), 404);
    }

    private function emptyCorrectionRow(string $list): array
    {
        return array_fill_keys($list === 'lines' ? InvoiceData::LINE_FIELDS : InvoiceData::VAT_FIELDS, null);
    }

    public function saveGroundTruth(InvoiceBenchmarkService $benchmark): void
    {
        $document = $this->query($benchmark)->findOrFail($this->documentId);
        abort_unless($document->runs()->whereKey($this->runId)->whereIn('status', ['succeeded', 'failed'])->exists(), 404);
        try {
            $truth = InvoiceData::verifiedValues($this->correction);
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $errors["correction.{$field}"] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
        if ($truth === []) {
            throw ValidationException::withMessages(['correction' => __('Enter at least one verified value.')]);
        }

        $document->update(['ground_truth' => $truth, 'verified_at' => now()]);
        Notification::make()->title(__('Verified values saved'))->success()->send();
    }

    public function useFixtureGroundTruth(InvoiceBenchmarkService $benchmark): void
    {
        $document = $this->query($benchmark)->findOrFail($this->documentId);
        abort_if($document->fixture === null, 404);

        $truth = InvoiceBenchmarkFixtures::expected($document->fixture);
        $document->update(['ground_truth' => $truth, 'verified_at' => now()]);
        $this->fillCorrection($truth);
        Notification::make()->title(__('Sample reference values saved'))->success()->send();
    }

    protected function getViewData(): array
    {
        $benchmark = app(InvoiceBenchmarkService::class);
        $documents = $this->query($benchmark);
        $selected = $this->documentId === null ? null : (clone $documents)->with('runs')->find($this->documentId);
        $run = $selected?->runs->firstWhere('id', $this->runId);

        return [
            'documents' => $this->activeView === 'history' ? (clone $documents)->latest()->limit(30)->get() : collect(),
            'selected' => $selected,
            'run' => $run,
            'demoComparison' => $run?->mode === 'demo' && $run->result !== null
                ? $this->demoComparison($run->result, InvoiceBenchmarkFixtures::expected($selected->fixture))
                : null,
            'requestMetrics' => $run === null ? collect() : DB::table('invoice_benchmark_requests')->where('run_id', $run->id)->orderBy('attempt')->get(),
            'stats' => $this->activeView === 'history' ? app(InvoiceBenchmarkDashboard::class)->summarize($documents) : null,
            'fixtures' => InvoiceBenchmarkFixtures::all(),
            'modes' => array_map(fn (string $mode): string => __($mode), InvoiceBenchmarkService::MODES),
            'statuses' => [
                'queued' => __('Waiting to be analyzed'),
                'processing' => __('Analyzing…'),
                'succeeded' => __('Analysis complete'),
                'failed' => __('Analysis failed'),
            ],
            'live' => (bool) config('invoice-benchmark.live'),
        ];
    }

    private function demoComparison(array $extracted, array $reference): array
    {
        $rows = [];
        $fields = array_unique(['invoice_number', 'supplier_nif', 'gross_total', ...array_keys($reference), ...array_keys($extracted)]);
        foreach ($fields as $field) {
            $actual = $extracted[$field] ?? null;
            $expected = $reference[$field] ?? null;
            if (! InvoiceData::available($actual) && ! InvoiceData::available($expected)) {
                continue;
            }

            $rows[$field] = [
                'actual' => $actual,
                'expected' => $expected,
                'status' => match (true) {
                    ! InvoiceData::available($actual) => 'missing',
                    ! InvoiceData::available($expected) => 'unverified',
                    InvoiceData::compare($actual, $expected, $field) => 'match',
                    default => 'incorrect',
                },
            ];
        }

        return $rows;
    }

    private function query(InvoiceBenchmarkService $benchmark): Builder
    {
        abort_unless(static::canAccess(), 403);

        return $benchmark->documents((int) auth()->id(), CurrentAccount::id());
    }

    private function requirePrivateTemporaryDisk(): void
    {
        $disk = config('livewire.temporary_file_upload.disk') ?: config('filesystems.default');
        $settings = config("filesystems.disks.{$disk}");
        if ($disk !== 'local' || ($settings['driver'] ?? null) !== 'local'
            || ($settings['visibility'] ?? 'private') !== 'private'
            || ($settings['root'] ?? null) !== storage_path('app/private')) {
            throw ValidationException::withMessages(['upload' => __('Configure Livewire to use the private local disk for benchmark uploads.')]);
        }
    }

    private function limit(string $field): void
    {
        $key = 'invoice-benchmark:'.$field.':'.auth()->id();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages([$field => __('Too many benchmark actions. Try again in one minute.')]);
        }
        RateLimiter::hit($key, 60);
    }
}
