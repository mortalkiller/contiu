<x-filament-panels::page>
    <x-filament::section heading="Documents">
        <div class="grid gap-6 lg:grid-cols-2">
            <form wire:submit="uploadDocument" class="space-y-3">
                <label for="invoice-upload" class="block text-sm font-medium">Invoice photo or PDF</label>
                <input id="invoice-upload" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" wire:model="upload" class="block w-full text-sm">
                <label for="invoice-camera" class="block text-sm font-medium">Take a photo</label>
                <input id="invoice-camera" type="file" accept="image/jpeg,image/png,image/webp" capture="environment" wire:model="upload" class="block w-full text-sm">
                @error('upload') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="upload,uploadDocument">Add document</x-filament::button>
                <span wire:loading wire:target="upload" class="text-sm" role="status">Uploading…</span>
            </form>
            <form wire:submit="loadDemo" class="space-y-3">
                <label for="invoice-fixture" class="block text-sm font-medium">Demo Mode</label>
                <x-filament::input.wrapper>
                    <x-filament::input.select id="invoice-fixture" wire:model="fixture">
                        @foreach ($fixtures as $key => $fixtureInfo)
                            <option value="{{ $key }}">{{ $fixtureInfo['label'] }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
                @error('fixture') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                <x-filament::button type="submit" color="gray">Run demo</x-filament::button>
                <p class="text-sm text-gray-500">Demo uses synthetic documents and prewritten responses.</p>
            </form>
        </div>

        @if ($documents->isNotEmpty())
            <div class="mt-5 flex flex-wrap gap-2">
                @foreach ($documents as $document)
                    <x-filament::button wire:key="invoice-{{ $document->id }}" type="button" color="gray" wire:click="selectDocument('{{ $document->id }}')">
                        {{ $fixtures[$document->fixture]['label'] ?? 'Upload '.substr($document->sha256, 0, 8) }}
                    </x-filament::button>
                @endforeach
            </div>
        @endif
    </x-filament::section>

    @if ($selected)
        <div class="grid items-start gap-6 lg:grid-cols-2">
            <x-filament::section heading="Original document">
                <p class="mb-3 text-sm text-gray-500">
                    {{ $selected->mime }} · {{ number_format($selected->bytes / 1024, 1) }} KB ·
                    @if (isset($selected->dimensions['pages']))
                        {{ $selected->dimensions['pages'] }} pages
                    @else
                        {{ $selected->dimensions['width'] }} × {{ $selected->dimensions['height'] }} px
                    @endif
                </p>
                @if (isset($selected->dimensions['pages']))
                    <x-filament::input.wrapper class="mb-3">
                        <x-filament::input.select wire:model.live="previewPage" aria-label="PDF page">
                            @foreach (range(1, $selected->dimensions['pages']) as $page)
                                <option value="{{ $page }}">Page {{ $page }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                @endif
                <img src="{{ route('filament.admin.invoice-benchmark.preview', ['tenant' => \Filament\Facades\Filament::getTenant(), 'document' => $selected->id, 'page' => $this->previewPage], false) }}"
                     alt="Original benchmark document" class="w-full rounded-lg">
            </x-filament::section>

            <x-filament::section heading="Extraction runs">
                <form wire:submit="processDocument" class="space-y-3">
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model="mode" aria-label="Processing mode">
                            <option value="qr">QR Only</option>
                            <option value="ai" @disabled(! $live)>AI Only</option>
                            <option value="qr_ai" @disabled(! $live)>QR + AI</option>
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                    @if (! $live)
                        <p class="text-sm text-gray-500">Paid Vertex requests are disabled.</p>
                    @endif
                    @error('mode') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                    <x-filament::button type="submit" wire:loading.attr="disabled">Process document</x-filament::button>
                </form>

                <div class="mt-4 flex flex-wrap gap-2" @if ($selected->runs->whereIn('status', ['queued', 'processing'])->isNotEmpty()) wire:poll.3s @endif>
                    @foreach ($selected->runs as $entry)
                        <x-filament::button wire:key="run-{{ $entry->id }}" type="button" color="{{ $entry->id === $this->runId ? 'primary' : 'gray' }}" wire:click="openRun({{ $entry->id }})">
                            {{ $modes[$entry->mode] }} · {{ ucfirst($entry->status) }}
                        </x-filament::button>
                    @endforeach
                </div>
                @if ($run)
                    @if ($run->error)
                        <p class="mt-4 text-sm text-danger-600" role="alert">{{ $run->error }}</p>
                    @endif
                    @if ($run->qr_output)
                        <p class="mt-4 text-sm">QR detected: {{ $run->qr_output['detected'] ? 'yes' : 'no' }} · {{ str($run->qr_output['status'])->replace('_', ' ') }}</p>
                    @endif
                    @if ($run->duration_ms !== null)
                        <p class="mt-2 text-sm">Processing time: {{ $run->duration_ms }} ms</p>
                    @endif
                    @if ($run->provider)
                        <p class="mt-2 text-sm">{{ $run->provider }} / {{ $run->model }} / {{ $run->region }} · {{ $requestMetrics->count() }} API requests · {{ max(0, $requestMetrics->count() - 1) }} retries</p>
                        @foreach ($requestMetrics as $request)
                            @php($usage = json_decode($request->usage ?? '{}', true))
                            <div class="mt-3 space-y-1 rounded-lg bg-gray-50 p-3 text-sm dark:bg-gray-800">
                                <p>Attempt {{ $request->attempt }} · {{ $request->http_status ?? 'No HTTP response' }} · {{ $request->duration_ms === null ? 'Unknown duration' : $request->duration_ms.' ms' }}</p>
                                <p>Input {{ $usage['total_input_tokens'] ?? '—' }} · Output {{ $usage['total_output_tokens'] ?? '—' }} · Reasoning {{ $usage['total_thought_tokens'] ?? '—' }} · Cached {{ $usage['total_cached_tokens'] ?? '—' }} · Total {{ $usage['total_tokens'] ?? '—' }}</p>
                                <p>Estimated USD {{ $request->estimated_usd ?? '—' }} · {{ data_get(json_decode($request->pricing, true), 'version') }}</p>
                            </div>
                        @endforeach
                    @endif
                @endif
            </x-filament::section>
        </div>

        @if ($run && in_array($run->status, ['succeeded', 'failed'], true))
            <x-filament::section heading="Extraction review">
                @if ($run->review)
                    <div class="mb-5 space-y-2 text-sm">
                        @foreach ($run->review['warnings'] as $warning)
                            <p>{{ $warning }}</p>
                        @endforeach
                        @if ($run->review['missing'])
                            <p>Missing core fields: {{ implode(', ', $run->review['missing']) }}</p>
                        @endif
                        @foreach ($run->review['differences'] as $key => $difference)
                            <p>{{ str($key)->headline() }} · QR {{ $difference['qr'] }} · AI {{ $difference['ai'] }}</p>
                        @endforeach
                    </div>
                @endif
                @if ($selected->verified_at)
                    <p class="mb-3 text-sm text-gray-500">Verified on {{ $selected->verified_at->format('Y-m-d H:i') }}</p>
                @endif
                <details class="mb-4 text-sm">
                    <summary class="cursor-pointer">Original extraction</summary>
                    <pre class="mt-2 max-h-80 overflow-auto rounded-lg bg-gray-50 p-3 dark:bg-gray-800">{{ json_encode($run->result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                </details>
                @if ($selected->fixture)
                    <x-filament::button type="button" color="gray" wire:click="useFixtureGroundTruth" class="mb-4">Use synthetic reference values</x-filament::button>
                @endif
                @if ($this->reviewLoaded)
                    <form wire:submit="saveGroundTruth" class="space-y-4">
                        <p class="text-sm text-gray-500">Verify populated fields against the document. Clear any field you cannot verify.</p>
                        {{ $this->reviewForm }}
                        @error('correction') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                        <x-filament::button type="submit" wire:loading.attr="disabled">Save verified ground truth</x-filament::button>
                    </form>
                @else
                    <x-filament::button type="button" wire:click="loadReview">Open extracted fields</x-filament::button>
                @endif
            </x-filament::section>
        @endif
    @endif

    <x-filament::section heading="Benchmark results">
        <p class="mb-4 text-sm text-gray-500">{{ $stats['documents'] }} processed documents · {{ $stats['demos'] }} demo runs. Latest 100 retained documents for this account and operator. Costs are API estimates in USD. Accuracy uses verified fields.</p>
        <div class="space-y-3">
            @foreach (['Overall' => $stats['overall'], ...$stats['groups']] as $label => $metrics)
                @php($modeKey = str($label)->before(' / ')->toString())
                <details class="rounded-lg bg-gray-50 p-4 dark:bg-gray-800" @if ($loop->first) open @endif>
                    <summary class="cursor-pointer font-medium">{{ $modes[$modeKey] ?? $label }}{{ str_contains($label, ' / ') ? ' / '.str($label)->after(' / ') : '' }} · {{ $metrics['runs'] }} runs</summary>
                    <div class="mt-3 space-y-2 text-sm">
                        <p>{{ $metrics['success'] }} succeeded · {{ $metrics['failed'] }} failed · {{ $metrics['requests'] }} API requests · {{ $metrics['retries'] }} retries</p>
                        <p>Latency (ms) · Mean {{ $metrics['avg_ms'] ?? '—' }} · Median {{ $metrics['median_ms'] ?? '—' }} · P95 {{ $metrics['p95_ms'] ?? '—' }}</p>
                        <p>Average reported tokens {{ $metrics['avg_tokens'] ?? '—' }} (n={{ $metrics['token_samples'] }})</p>
                        <p>Estimated USD · Total {{ $metrics['total_cost'] ?? '—' }} · Average {{ $metrics['avg_cost'] ?? '—' }} (n={{ $metrics['cost_samples'] }})</p>
                        <p>Verified runs {{ $metrics['verified'] }} · Fully correct {{ $metrics['correct'] }} · Missing fields {{ $metrics['missing_rate'] === null ? '—' : $metrics['missing_rate'].'%' }} · Correction required {{ $metrics['correction_rate'] === null ? '—' : $metrics['correction_rate'].'%' }}</p>
                        <p>Estimated USD per correct extraction {{ $metrics['cost_per_correct'] ?? '—' }} (n={{ $metrics['verified_cost_samples'] }})</p>
                        @if ($metrics['projections'])
                            <p class="font-medium">Estimated monthly API cost (n={{ $metrics['cost_samples'] }})</p>
                            <ul class="flex flex-wrap gap-4">
                                @foreach ($metrics['projections'] as $volume => $cost)
                                    <li>{{ number_format($volume) }} invoices · USD {{ $cost }}</li>
                                @endforeach
                            </ul>
                        @endif
                        @if ($metrics['fields'])
                            <div class="overflow-x-auto">
                                <table class="w-full text-left">
                                    <thead><tr><th>Field</th><th>Correct</th><th>Incorrect</th><th>Missing</th><th>Accuracy</th></tr></thead>
                                    <tbody>
                                        @foreach ($metrics['fields'] as $key => $counts)
                                            <tr><th class="font-normal">{{ str($key)->headline() }}</th><td>{{ $counts['correct'] }}</td><td>{{ $counts['incorrect'] }}</td><td>{{ $counts['missing'] }}</td><td>{{ round(100 * $counts['correct'] / array_sum($counts), 1) }}%</td></tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </details>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>
