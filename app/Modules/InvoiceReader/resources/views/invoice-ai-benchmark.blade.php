@use('App\Modules\InvoiceReader\InvoiceData')
@use('App\Modules\InvoiceReader\InvoiceValidationService')

<x-filament-panels::page>
    <x-filament::tabs :label="__('Invoice benchmark')">
        <x-filament::tabs.item :active="$this->activeView === 'demo'" wire:click="openView('demo')">
            {{ __('Try an example') }}
        </x-filament::tabs.item>
        <x-filament::tabs.item :active="$this->activeView === 'analyze'" wire:click="openView('analyze')">
            {{ __('Analyze a document') }}
        </x-filament::tabs.item>
        <x-filament::tabs.item :active="$this->activeView === 'history'" wire:click="openView('history')">
            {{ __('History and statistics') }}
        </x-filament::tabs.item>
    </x-filament::tabs>

    @if ($this->activeView === 'demo')
        <x-filament::section :heading="__('Try an example')" :description="__('See what an extraction gets right or misses on a sample invoice. Free and simulated.')">
            <form wire:submit="loadDemo" class="flex flex-wrap items-end gap-3">
                <div class="min-w-0 flex-1">
                    <label for="invoice-fixture" class="mb-1 block text-sm font-medium">{{ __('Sample invoice') }}</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="invoice-fixture" wire:model="fixture">
                            @foreach ($fixtures as $key => $fixtureInfo)
                                <option value="{{ $key }}">{{ __($fixtureInfo['label']) }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                    @error('fixture') <p class="mt-1 text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                </div>
                <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="loadDemo">{{ __('Show example') }}</x-filament::button>
            </form>
        </x-filament::section>
    @elseif ($this->activeView === 'analyze')
        <x-filament::section :heading="__('Analyze a document')" :description="__('Choose an invoice and how you want to read it.')">
            <form wire:submit="processDocument" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="min-w-0 space-y-2">
                        <label for="invoice-upload" class="block text-sm font-medium">{{ $selected ? __('Replace document (optional)') : __('Invoice photo or PDF') }}</label>
                        <input id="invoice-upload" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" wire:model="upload" class="block w-full text-sm">
                        <details class="text-sm">
                            <summary class="cursor-pointer">{{ __('Use camera') }}</summary>
                            <input type="file" accept="image/jpeg,image/png,image/webp" capture="environment" aria-label="{{ __('Take a photo of the invoice') }}" wire:model="upload" class="mt-2 block w-full text-sm">
                        </details>
                        @error('upload') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                        <p wire:loading wire:target="upload" class="text-sm text-gray-500" role="status">{{ __('Uploading…') }}</p>
                    </div>

                    <div class="min-w-0 space-y-2">
                        <label for="invoice-method" class="block text-sm font-medium">{{ __('Analysis method') }}</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="invoice-method" wire:model="mode">
                                <option value="qr">{{ __('Read QR code (free)') }}</option>
                                <option value="ai" @disabled(! $live)>{{ __('Read with AI') }}</option>
                                <option value="qr_ai" @disabled(! $live)>{{ __('Read QR code and use AI') }}</option>
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                        @if (! $live)
                            <p class="text-sm text-gray-500">{{ __('Paid AI methods are currently unavailable.') }}</p>
                        @endif
                        @error('mode') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                        <p class="text-sm text-gray-500">{{ __('Only eligible Portuguese VAT numbers are sent to VIES to look up supplier and customer names and addresses.') }}</p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-4">
                    <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="upload,processDocument,uploadDocument">
                        {{ __('Analyze document') }}
                    </x-filament::button>
                    @if ($this->upload)
                        <x-filament::link tag="button" wire:click="uploadDocument" wire:loading.attr="disabled" wire:target="upload,processDocument,uploadDocument">
                            {{ __('Save for later') }}
                        </x-filament::link>
                    @endif
                </div>
            </form>
        </x-filament::section>
    @else
        <x-filament::section :heading="__('Previous analyses')">
            @if ($documents->isEmpty())
                <p class="text-sm text-gray-500">{{ __('Your examples and document analyses will appear here.') }}</p>
            @else
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="min-w-0">
                        <label for="benchmark-history-document" class="mb-1 block text-sm font-medium">{{ __('Document') }}</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="benchmark-history-document" wire:change="selectDocument($event.target.value)">
                                <option value="" disabled @selected($selected === null)>{{ __('Choose a document') }}</option>
                                @foreach ($documents as $document)
                                    <option value="{{ $document->id }}" @selected($selected?->id === $document->id)>
                                        {{ $document->fixture ? __($fixtures[$document->fixture]['label']) : __('File :id', ['id' => substr($document->sha256, 0, 8)]) }} · {{ $document->created_at->translatedFormat('d M H:i') }}
                                    </option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>
                    @if ($selected)
                        <div class="min-w-0" wire:key="benchmark-history-{{ $selected->id }}">
                            <label for="benchmark-history-run" class="mb-1 block text-sm font-medium">{{ __('Analysis') }}</label>
                            <x-filament::input.wrapper>
                                <x-filament::input.select id="benchmark-history-run" wire:change="openRun($event.target.value)" :disabled="$selected->runs->isEmpty()">
                                    @if ($selected->runs->isEmpty())
                                        <option>{{ __('No analyses yet') }}</option>
                                    @endif
                                    @foreach ($selected->runs->sortByDesc('id') as $entry)
                                        <option value="{{ $entry->id }}" @selected($run?->id === $entry->id)>
                                            {{ $modes[$entry->mode] }} · {{ $statuses[$entry->status] }}
                                        </option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </div>
                    @endif
                </div>

                @if ($selected)
                    <x-filament::link tag="button" wire:click="analyzeSelected" class="mt-4">
                        {{ __('Use another analysis method') }}
                    </x-filament::link>
                @endif
            @endif
        </x-filament::section>
    @endif

    @if ($selected)
        <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
            <div class="min-w-0">
                <x-filament::section :heading="__('Original document')">
                    <p class="mb-3 text-sm text-gray-500">
                        {{ $selected->mime }} · {{ number_format($selected->bytes / 1024, 1) }} KB ·
                        @if (isset($selected->dimensions['pages']))
                            {{ trans_choice(':count page|:count pages', $selected->dimensions['pages']) }}
                        @else
                            {{ $selected->dimensions['width'] }} × {{ $selected->dimensions['height'] }} px
                        @endif
                    </p>
                    @if (isset($selected->dimensions['pages']))
                        <x-filament::input.wrapper class="mb-3">
                            <x-filament::input.select wire:model.live="previewPage" :aria-label="__('PDF page')">
                                @foreach (range(1, $selected->dimensions['pages']) as $page)
                                    <option value="{{ $page }}">{{ __('Page :number', ['number' => $page]) }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    @endif
                    <img src="{{ route('filament.tools.invoice-benchmark.preview', ['tenant' => \Filament\Facades\Filament::getTenant(), 'document' => $selected->id, 'page' => $this->previewPage], false) }}"
                         alt="{{ __('Original benchmark document') }}" class="w-full rounded-lg">
                </x-filament::section>
            </div>

            <div class="min-w-0 space-y-6 @container">
                @if ($run?->mode === 'demo' && in_array($run->status, ['succeeded', 'failed'], true))
                    <x-filament::section :heading="__('Demo comparison')">
                        @if ($demoComparison)
                            <p class="mb-3 text-sm text-gray-500">
                                {{ trans_choice(':count incorrect field|:count incorrect fields', count(array_filter($demoComparison, fn (array $row): bool => $row['status'] === 'incorrect'))) }} ·
                                {{ trans_choice(':count missing field|:count missing fields', count(array_filter($demoComparison, fn (array $row): bool => $row['status'] === 'missing'))) }}
                            </p>
                            <table class="w-full table-fixed text-left text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200 dark:border-gray-700">
                                        <th scope="col" class="py-2 pr-2">{{ __('Field') }}</th>
                                        <th scope="col" class="py-2 pr-2">{{ __('Simulated extraction') }}</th>
                                        <th scope="col" class="py-2">{{ __('Correct value') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($demoComparison as $field => $comparison)
                                        <tr class="border-b border-gray-200 align-top dark:border-gray-700">
                                            <th scope="row" class="break-words py-3 pr-2 font-medium">
                                                {{ InvoiceData::fieldLabel($field) }}
                                                @if ($comparison['status'] !== 'match')
                                                    <div class="mt-1">
                                                        <x-filament::badge :color="$comparison['status'] === 'incorrect' ? 'danger' : 'warning'">
                                                            {{ match ($comparison['status']) { 'incorrect' => __('Incorrect value'), 'missing' => __('Missing'), default => __('Unverified value') } }}
                                                        </x-filament::badge>
                                                    </div>
                                                @endif
                                            </th>
                                            @foreach (['actual', 'expected'] as $column)
                                                <td class="break-words py-3 pr-2">
                                                    @include('invoice-reader::partials.invoice-benchmark-demo-value', ['value' => $comparison[$column], 'field' => $field])
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @elseif ($demoComparison === [])
                            <p class="text-sm text-gray-500">{{ __('This sample has no readable fields to compare.') }}</p>
                        @elseif ($run->status === 'failed')
                            <p class="text-sm text-danger-600" role="alert">{{ __($run->error) }}</p>
                        @endif
                    </x-filament::section>
                @endif

                @if ($run && in_array($run->status, ['succeeded', 'failed'], true))
                    <x-filament::section :heading="__('Review and edit values')" collapsible :collapsed="$run->mode === 'demo'" wire:key="extraction-review-{{ $run->id }}">
                        @if ($selected->fixture)
                            <x-filament::button type="button" color="gray" wire:click="useFixtureGroundTruth" class="mb-4">{{ __('Use sample reference values') }}</x-filament::button>
                        @endif
                        @if ($this->reviewLoaded)
                            <form wire:submit="saveGroundTruth" class="space-y-4">
                                <p class="text-sm text-gray-500">{{ __('Check the values against the document, then save the ones you can verify.') }}</p>
                                @include('invoice-reader::partials.invoice-benchmark-paper')
                                @error('correction') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                                @foreach (['correction.lines', 'correction.vat'] as $rowPath)
                                    @error($rowPath) <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                                @endforeach
                                <x-filament::button type="submit" wire:loading.attr="disabled">{{ __('Save verified values') }}</x-filament::button>
                            </form>
                        @else
                            <x-filament::button type="button" wire:click="loadReview">{{ __('Open extracted fields') }}</x-filament::button>
                        @endif
                    </x-filament::section>
                @endif
            </div>
        </div>

        <div @class(['grid items-start gap-6', 'xl:grid-cols-2' => $run?->mode !== 'demo'])>
            @unless ($run?->mode === 'demo' && in_array($run->status, ['succeeded', 'failed'], true))
                <x-filament::section :heading="__('Analysis result')" wire:key="benchmark-result-{{ $run?->id ?? $selected->id }}">
                    @if ($run && in_array($run->status, ['queued', 'processing'], true))
                        <div wire:poll.3s="refreshAnalysis" aria-hidden="true"></div>
                    @endif
                    @if ($run)
                        <div role="status" class="flex flex-wrap items-center gap-2 text-sm">
                            <span class="font-medium">{{ $modes[$run->mode] }}</span>
                            <x-filament::badge :color="match ($run->status) { 'succeeded' => 'success', 'failed' => 'danger', default => 'gray' }">
                                {{ $statuses[$run->status] }}
                            </x-filament::badge>
                        </div>
                        <div class="mt-3 space-y-1 text-sm">
                            @forelse ($requestMetrics as $request)
                                <p>
                                    @if ($requestMetrics->count() > 1)
                                        {{ __('Attempt :number', ['number' => $request->attempt]) }} ·
                                    @endif
                                    @if ($request->estimated_usd === null)
                                        {{ __('Estimated AI cost unavailable') }}
                                    @else
                                        {{ __('Estimated AI cost (USD) · :cost', ['cost' => $request->estimated_usd]) }}
                                    @endif
                                </p>
                            @empty
                                @if (in_array($run->status, ['succeeded', 'failed'], true))
                                    <p>{{ __('No AI request was made.') }}</p>
                                @endif
                            @endforelse
                        </div>
                        @if ($run->error)
                            <p class="mt-3 text-sm text-danger-600" role="alert">{{ __($run->error) }}</p>
                        @endif
                        @if ($run->qr_output || $run->duration_ms !== null || $run->provider)
                            <details class="mt-3 space-y-2 text-sm">
                                <summary class="cursor-pointer font-medium">{{ __('Analysis details') }}</summary>
                                @if ($run->qr_output)
                                    <p class="text-sm">{{ match ($run->qr_output['status']) {
                                        'parsed' => __('QR code read successfully.'),
                                        'not_found' => __('No QR code found.'),
                                        'invalid' => __('The QR code does not contain valid invoice data.'),
                                        'ambiguous' => __('Multiple invoice QR codes found.'),
                                        default => __('Could not read the QR code.'),
                                    } }}</p>
                                @endif
                                @if ($run->duration_ms !== null)
                                    <p class="mt-2 text-sm">{{ __('Processing time · :time ms', ['time' => $run->duration_ms]) }}</p>
                                @endif
                                @if ($run->provider)
                                    <p class="mt-2 text-sm">{{ $run->provider }} / {{ $run->model }} / {{ $run->region }} · {{ trans_choice(':count API request|:count API requests', $requestMetrics->count()) }} · {{ trans_choice(':count retry|:count retries', max(0, $requestMetrics->count() - 1)) }}</p>
                                    @foreach ($requestMetrics as $request)
                                        @php($usage = json_decode($request->usage ?? '{}', true))
                                        <div class="mt-3 space-y-1 rounded-lg bg-gray-50 p-3 text-sm dark:bg-gray-800">
                                            <p>{{ __('Attempt :number', ['number' => $request->attempt]) }} · {{ $request->http_status ?? __('No HTTP response') }} · {{ $request->duration_ms === null ? __('Unknown duration') : $request->duration_ms.' ms' }}</p>
                                            <p>{{ __('Input :input · Output :output · Reasoning :reasoning · Cached :cached · Total :total', ['input' => $usage['total_input_tokens'] ?? '—', 'output' => $usage['total_output_tokens'] ?? '—', 'reasoning' => $usage['total_thought_tokens'] ?? '—', 'cached' => $usage['total_cached_tokens'] ?? '—', 'total' => $usage['total_tokens'] ?? '—']) }}</p>
                                            <p>{{ __('Estimated cost (USD) · :cost', ['cost' => $request->estimated_usd ?? '—']) }} · {{ data_get(json_decode($request->pricing, true), 'version') }}</p>
                                        </div>
                                    @endforeach
                                @endif
                            </details>
                        @endif
                    @else
                        <p class="text-sm text-gray-500">{{ $this->activeView === 'analyze' ? __('Choose a method to analyze this document.') : __('This document has no analysis yet.') }}</p>
                    @endif
                </x-filament::section>
            @endunless

            @if ($run && in_array($run->status, ['succeeded', 'failed'], true))
                <x-filament::section :heading="__('Review details')" class="min-w-0" wire:key="extraction-review-details-{{ $run->id }}">
                    <div class="space-y-3">
                        @if (! empty($run->review['warnings']) || ! empty($run->review['missing']) || ! empty($run->review['differences']))
                            <div class="space-y-2 text-sm">
                                @foreach ($run->review['warnings'] as $warning)
                                    <p>{{ InvoiceValidationService::warningLabel($warning) }}</p>
                                @endforeach
                                @if ($run->review['missing'])
                                    <p>{{ __('Key fields missing · :fields', ['fields' => implode(', ', array_map(InvoiceData::fieldLabel(...), $run->review['missing']))]) }}</p>
                                @endif
                                @foreach ($run->review['differences'] as $key => $difference)
                                    <p>{{ __(':field · QR :qr · AI :ai', ['field' => InvoiceData::fieldLabel($key), 'qr' => $difference['qr'], 'ai' => $difference['ai']]) }}</p>
                                @endforeach
                            </div>
                        @endif
                        @if ($run->mode === 'demo' && $run->duration_ms !== null)
                            <p class="text-sm text-gray-500">{{ __('Processing time · :time ms', ['time' => $run->duration_ms]) }}</p>
                        @endif
                        @if ($selected->verified_at)
                            <p class="text-sm text-gray-500">{{ __('Verified on :date', ['date' => $selected->verified_at->translatedFormat('d M Y, H:i')]) }}</p>
                        @endif
                        <details class="text-sm">
                            <summary class="cursor-pointer">{{ __('Original extraction') }}</summary>
                            <pre class="mt-2 max-h-80 overflow-auto rounded-lg bg-gray-50 p-3 dark:bg-gray-800">{{ json_encode($run->result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                        </details>
                        @if (! $selected->verified_at && ! empty($run->review['vies_supplier']))
                            <p class="text-sm">{{ __('Supplier details from VIES') }} · {{ implode(', ', array_map(InvoiceData::fieldLabel(...), array_keys($run->review['vies_supplier']))) }}</p>
                        @endif
                        @if (! $selected->verified_at && ! empty($run->review['vies_customer']))
                            <p class="text-sm">{{ __('Customer details from VIES') }} · {{ implode(', ', array_map(InvoiceData::fieldLabel(...), array_keys($run->review['vies_customer']))) }}</p>
                        @endif
                    </div>
                </x-filament::section>
            @endif
        </div>
    @endif

    @if ($stats !== null)
        <x-filament::section :heading="__('Benchmark statistics')" collapsible collapsed>
            <p class="mb-4 text-sm text-gray-500">{{ trans_choice(':count document processed|:count documents processed', $stats['documents']) }} · {{ trans_choice(':count simulation|:count simulations', $stats['demos']) }}. {{ __('Based on up to 100 saved documents. Costs are estimates in USD. Accuracy is calculated from verified fields.') }}</p>
            <div class="space-y-3">
                @foreach (['Overall' => $stats['overall'], ...$stats['groups']] as $label => $metrics)
                    @php($modeKey = str($label)->before(' / ')->toString())
                    <details class="rounded-lg bg-gray-50 p-4 dark:bg-gray-800" @if ($loop->first) open @endif>
                        <summary class="cursor-pointer font-medium">{{ $modes[$modeKey] ?? __($label) }}{{ str_contains($label, ' / ') ? ' / '.str($label)->after(' / ') : '' }} · {{ trans_choice(':count analysis|:count analyses', $metrics['runs']) }}</summary>
                        <div class="mt-3 space-y-2 text-sm">
                            <p>{{ trans_choice(':count successful analysis|:count successful analyses', $metrics['success']) }} · {{ trans_choice(':count failed analysis|:count failed analyses', $metrics['failed']) }} · {{ trans_choice(':count API request|:count API requests', $metrics['requests']) }} · {{ trans_choice(':count retry|:count retries', $metrics['retries']) }}</p>
                            <p>{{ __('Response time (ms) · Mean :mean · Median :median · 95th percentile :p95', ['mean' => $metrics['avg_ms'] ?? '—', 'median' => $metrics['median_ms'] ?? '—', 'p95' => $metrics['p95_ms'] ?? '—']) }}</p>
                            <p>{{ __('Average tokens reported · :average (n=:count)', ['average' => $metrics['avg_tokens'] ?? '—', 'count' => $metrics['token_samples']]) }}</p>
                            <p>{{ __('Estimated cost (USD) · Total :total · Average :average (n=:count)', ['total' => $metrics['total_cost'] ?? '—', 'average' => $metrics['avg_cost'] ?? '—', 'count' => $metrics['cost_samples']]) }}</p>
                            <p>{{ __('Verified analyses · :verified · All fields correct :correct · Missing fields :missing · Corrections needed :corrections', ['verified' => $metrics['verified'], 'correct' => $metrics['correct'], 'missing' => $metrics['missing_rate'] === null ? '—' : $metrics['missing_rate'].'%', 'corrections' => $metrics['correction_rate'] === null ? '—' : $metrics['correction_rate'].'%']) }}</p>
                            <p>{{ __('Estimated cost per correct extraction (USD) · :cost (n=:count)', ['cost' => $metrics['cost_per_correct'] ?? '—', 'count' => $metrics['verified_cost_samples']]) }}</p>
                            @if ($metrics['projections'])
                                <p class="font-medium">{{ __('Estimated monthly API cost (n=:count)', ['count' => $metrics['cost_samples']]) }}</p>
                                <ul class="flex flex-wrap gap-4">
                                    @foreach ($metrics['projections'] as $volume => $cost)
                                        <li>{{ __(':volume invoices · USD :cost', ['volume' => number_format($volume), 'cost' => $cost]) }}</li>
                                    @endforeach
                                </ul>
                            @endif
                            @if ($metrics['fields'])
                                <div class="overflow-x-auto">
                                    <table class="w-full text-left">
                                        <thead><tr><th>{{ __('Field') }}</th><th>{{ __('Correct') }}</th><th>{{ __('Incorrect') }}</th><th>{{ __('Missing') }}</th><th>{{ __('Accuracy') }}</th></tr></thead>
                                        <tbody>
                                            @foreach ($metrics['fields'] as $key => $counts)
                                                <tr><th class="font-normal">{{ InvoiceData::fieldLabel($key) }}</th><td>{{ $counts['correct'] }}</td><td>{{ $counts['incorrect'] }}</td><td>{{ $counts['missing'] }}</td><td>{{ round(100 * $counts['correct'] / array_sum($counts), 1) }}%</td></tr>
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
    @endif
</x-filament-panels::page>
