@use('App\Enums\InvoiceImportStatus')
@use('App\Filament\Resources\Expenses\ExpenseResource')
@use('App\Support\Money')
@use('Illuminate\Support\Carbon')
@php
    $money = fn (?int $cents): string => Money::format($cents);
    $day = fn (?string $date): string => $date === null ? '' : Carbon::parse($date)->translatedFormat(app()->getLocale() === 'pt_PT' ? 'j \d\e F \d\e Y' : 'F j, Y');
    $shortDay = fn (mixed $date): string => Carbon::parse($date)->format(app()->getLocale() === 'pt_PT' ? 'd/m/Y' : 'm/d/Y');
    $percent = fn (string $rate): string => rtrim(rtrim(number_format((float) $rate * 100, 2, ',', ''), '0'), ',').'%';
    $fileUrl = fn (\App\Models\InvoiceImport $item): string => $fileBase.$item->id;
    $accept = 'application/pdf,image/jpeg,image/png,image/webp';
    $draft = $import?->data ?? [];
    $icons = [
        'upload' => 'M12 15V4M7 9l5-5 5 5M4 20h16',
        'camera' => 'M4 8h3l2-3h6l2 3h3v11H4zM12 17a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
        'file' => 'M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8zM14 3v5h5',
        'plus' => 'M12 5v14M5 12h14',
        'check' => 'M5 12l5 5 9-10',
    ];
    $icon = fn (string $name): string => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="'.$icons[$name].'"/></svg>';
@endphp

<x-filament-panels::page>
    <div class="contiu-inv"
        x-data="{ over: false }"
        x-on:dragover.prevent="over = true"
        x-on:dragleave.prevent="over = false"
        x-on:drop.prevent="over = false; @if ($canConfirm) $wire.uploadMultiple('files', [...$event.dataTransfer.files]) @endif"
        x-bind:class="{ 'is-over': over }"
        @if ($reading) wire:poll.2s @endif>

        {{-- One picker for files and one for the camera: every way in ends in the same list. --}}
        @if ($canConfirm)
            <input type="file" class="sr-only" x-ref="files" wire:model="files" multiple accept="{{ $accept }}" aria-label="{{ __('Choose files') }}">
            <input type="file" class="sr-only" x-ref="camera" wire:model="files" multiple accept="image/*" capture="environment" aria-label="{{ __('Take a photo') }}">
        @endif

        @foreach ($errors->get('files') as $message)
            <p class="contiu-inv-error" role="alert">{{ $message }}</p>
        @endforeach

        @if ($imports->isEmpty())
            {{-- Nothing yet: only the place to drop the invoices. --}}
            <div class="contiu-inv-empty">
                <span class="contiu-inv-empty-icon">{!! $icon('upload') !!}</span>
                <h2>{{ __('Drag the invoices here') }}</h2>
                <p>{{ __('PDF or photo, up to :count at a time. Each one becomes an expense with the file kept as its supporting document.', ['count' => \App\Models\InvoiceImport::BATCH]) }}</p>
                @if ($canConfirm)
                    <div class="contiu-inv-actions">
                        <x-filament::button x-on:click="$refs.files.click()" wire:loading.attr="disabled" wire:target="files">{{ __('Choose files') }}</x-filament::button>
                        <x-filament::button color="gray" x-on:click="$refs.camera.click()" wire:loading.attr="disabled" wire:target="files">{{ __('Take a photo') }}</x-filament::button>
                    </div>
                    <p class="contiu-inv-note" wire:loading wire:target="files">{{ __('Sending the files…') }}</p>
                @else
                    <p class="contiu-inv-note">{{ __('This account is read-only: invoices cannot be imported.') }}</p>
                @endif
            </div>
            <p class="contiu-inv-foot">
                {{ __('Do you have the invoices file from the Portal das Finanças?') }}
                <x-filament::link :href="$csvUrl">{{ __('Import the e-Fatura CSV') }}</x-filament::link>
                <br>
                {{ $usesAi ? __('Contiu reads the invoice with AI in the European Union; the file stays in your account only.') : __('Contiu reads the invoice\'s QR code; the file stays in your account only.') }}
            </p>
        @elseif ($allDone)
            {{-- Everything dealt with: what became of each invoice. --}}
            @php
                $outcomes = $imports->filter(fn ($item) => $item->status->isHandled())->groupBy(fn ($item) => $item->outcome['kind'] ?? 'dismissed');
                $unread = $imports->where('status', InvoiceImportStatus::Unreadable);
                $sum = fn ($items) => $money($items->sum(fn ($item) => (int) ($item->outcome['total'] ?? 0)));
            @endphp
            <div class="contiu-inv-done">
                <span class="contiu-inv-empty-icon is-success">{!! $icon('check') !!}</span>
                <h2>{{ __('Everything is in place') }}</h2>
                <p>{{ trans_choice('{1} You dealt with :done of 1 invoice.|[2,*] You dealt with :done of :count invoices.', $imports->count(), ['done' => $handled]) }}</p>

                <ul class="contiu-inv-summary">
                    @if ($new = $outcomes->get('new'))
                        @php $newExpenses = $new->sum(fn ($item) => (int) ($item->outcome['expenses'] ?? 1)); $newSuppliers = $new->filter(fn ($item) => $item->outcome['supplier_new'] ?? false)->count(); @endphp
                        <li>
                            <span><b>{{ trans_choice('{1} 1 new expense|[2,*] :count new expenses', $newExpenses) }}</b>
                                @if ($newSuppliers > 0) <small>{{ trans_choice('{1} 1 new supplier|[2,*] :count new suppliers', $newSuppliers) }}</small> @endif</span>
                            <i>{{ $sum($new) }}</i>
                        </li>
                    @endif
                    @if ($joined = $outcomes->get('joined'))
                        <li>
                            <span><b>{{ trans_choice('{1} 1 invoice joined to an expense you already had|[2,*] :count invoices joined to expenses you already had', $joined->count()) }}</b>
                                <small>{{ $joined->map(fn ($item) => $item->outcome['label'] ?? '')->filter()->join(', ') }}</small></span>
                            <i>{{ $sum($joined) }}</i>
                        </li>
                    @endif
                    @if ($sales = $outcomes->get('sale'))
                        <li>
                            <span><b>{{ trans_choice('{1} 1 sale recorded|[2,*] :count sales recorded', $sales->count()) }}</b>
                                <small>{{ $sales->map(fn ($item) => $item->outcome['label'] ?? '')->filter()->join(', ') }}</small></span>
                            <i>{{ $sum($sales) }}</i>
                        </li>
                    @endif
                    @if ($duplicates = $outcomes->get('duplicate'))
                        <li>
                            <span><b>{{ trans_choice('{1} 1 was already imported|[2,*] :count were already imported', $duplicates->count()) }}</b>
                                <small>{{ __('We did not change anything.') }}</small></span>
                        </li>
                    @endif
                    @if ($dismissed = $outcomes->get('dismissed'))
                        <li>
                            <span><b>{{ trans_choice('{1} 1 left out|[2,*] :count left out', $dismissed->count()) }}</b></span>
                        </li>
                    @endif
                    @foreach ($unread as $item)
                        <li class="is-fail">
                            <span><b>{{ $item->filename }}</b> <small>{{ __('We could not read it') }}</small></span>
                            <x-filament::link tag="button" wire:click="open({{ $item->id }})">{{ __('See what to do') }}</x-filament::link>
                        </li>
                    @endforeach
                </ul>

                <div class="contiu-inv-actions">
                    @if ($canConfirm)
                        <x-filament::button wire:click="startOver">{{ __('Import more invoices') }}</x-filament::button>
                    @endif
                    <x-filament::button color="gray" tag="a" :href="ExpenseResource::getUrl()">{{ __('See expenses') }}</x-filament::button>
                </div>
            </div>
        @else
            {{-- The workspace: the list, the invoice, and what it will become. --}}
            <div class="contiu-inv-head">
                <div class="contiu-inv-progress" role="status">
                    <span><b>{{ $handled }}</b> {{ __('of :count dealt with', ['count' => $imports->count()]) }}</span>
                    <span class="contiu-inv-bar"><i style="width: {{ $imports->count() > 0 ? round($handled / $imports->count() * 100) : 0 }}%"></i></span>
                </div>
                @if ($canConfirm)
                    <span class="contiu-inv-note" wire:loading wire:target="files">{{ __('Sending the files…') }}</span>
                    <x-filament::button color="gray" size="sm" x-on:click="$refs.files.click()">{{ __('Add invoices') }}</x-filament::button>
                    <x-filament::button color="gray" size="sm" class="contiu-inv-only-touch" x-on:click="$refs.camera.click()">{{ __('Take a photo') }}</x-filament::button>
                @endif
            </div>

            <div class="contiu-inv-work"
                x-on:keydown.window="
                    if (['INPUT', 'TEXTAREA', 'SELECT', 'BUTTON', 'A'].includes($event.target.tagName) || $event.metaKey || $event.ctrlKey || $event.altKey) return;
                    if ($event.key === 'Enter' && $refs.primary && ! $refs.primary.disabled) { $event.preventDefault(); $refs.primary.click(); }
                    if ($event.key === 'ArrowRight' && $refs.later) { $event.preventDefault(); $refs.later.click(); }
                ">
                <nav class="contiu-inv-queue" aria-label="{{ __('Invoices') }}">
                    <div class="contiu-inv-queue-head">{{ __('Invoices') }} <span>{{ $imports->count() }}</span></div>
                    @foreach ($imports as $item)
                        @php $state = $states[$item->id]; $itemDraft = $item->data ?? []; @endphp
                        <button type="button" wire:key="inv-{{ $item->id }}" wire:click="open({{ $item->id }})"
                            class="contiu-inv-q is-{{ $state['tone'] }}" @if ($item->id === $import?->id) aria-current="true" @endif>
                            <span class="contiu-inv-thumb @if ($item->isPdf()) is-pdf @endif" aria-hidden="true"></span>
                            <b>{{ $item->status->isReading() ? __('Reading…') : $state['name'] }}</b>
                            <i>{{ isset($itemDraft['total']) && ! $item->status->isReading() ? $money($itemDraft['total']) : '' }}</i>
                            <small>{{ $item->status->isReading() ? $item->filename : $state['label'] }}</small>
                        </button>
                    @endforeach
                </nav>

                @if ($import === null)
                    <div class="contiu-inv-doc"><p class="contiu-inv-note">{{ __('Choose an invoice from the list.') }}</p></div>
                @else
                    <div class="contiu-inv-doc" wire:key="doc-{{ $import->id }}">
                        @if ($import->isPdf())
                            <iframe src="{{ $fileUrl($import) }}#toolbar=0&navpanes=0&view=FitH" title="{{ $import->filename }}" loading="lazy"></iframe>
                        @else
                            <img src="{{ $fileUrl($import) }}" alt="{{ __('Photo of the invoice :name', ['name' => $import->filename]) }}">
                        @endif
                        <div class="contiu-inv-doc-tools">
                            <span>{{ $import->filename }}</span>
                            <x-filament::link :href="$fileUrl($import)" target="_blank" rel="noopener">{{ __('Open the file') }}</x-filament::link>
                        </div>
                    </div>

                    <section class="contiu-inv-panel" wire:key="panel-{{ $import->id }}-{{ $mode }}" aria-live="polite">
                        @error('current') <p class="contiu-inv-error" role="alert">{{ $message }}</p> @enderror

                        @if ($import->status->isReading())
                            <div class="contiu-inv-top">
                                <span class="contiu-inv-who">{{ __('Reading the invoice…') }}</span>
                                <span class="contiu-inv-meta">{{ __('It takes a few seconds. You can go on with the others.') }}</span>
                            </div>
                        @elseif ($import->status === InvoiceImportStatus::Unreadable && $mode === 'manual')
                            @include('filament.pages.invoice-import.by-hand')
                        @elseif ($import->status === InvoiceImportStatus::Unreadable)
                            <div class="contiu-inv-top">
                                <span class="contiu-inv-who">{{ __('We could not read this invoice') }}</span>
                                <span class="contiu-inv-meta">{{ match ($import->error) {
                                    'not_invoice' => __('This document does not look like an invoice: it may be a credit note, a receipt or a quote.'),
                                    'no_qr' => __('We did not find the invoice\'s QR code in the file.'),
                                    default => __('The image is hard to read, or it has no QR code.'),
                                } }}</span>
                            </div>
                            <p class="contiu-inv-out is-muted"><b>{{ __('The quickest way is to photograph it again.') }}</b>
                                <small>{{ __('With good light and the QR code inside the picture. If you do not have it with you, fill in the essentials here.') }}</small></p>
                            @if ($canConfirm)
                                <div class="contiu-inv-act">
                                    <x-filament::button size="lg" x-on:click="$refs.camera.click(); $wire.replace()">{{ __('Take another photo') }}</x-filament::button>
                                    <div class="contiu-inv-act-row">
                                        <x-filament::link tag="button" wire:click="fillByHand">{{ __('Fill in by hand') }}</x-filament::link>
                                        <x-filament::link tag="button" color="gray" wire:click="dismiss">{{ __('Remove from the list') }}</x-filament::link>
                                    </div>
                                </div>
                            @endif
                        @elseif ($import->status->isHandled())
                            @include('filament.pages.invoice-import.handled')
                        @elseif ($mode === 'correct')
                            @include('filament.pages.invoice-import.correct')
                        @elseif ($mode === 'pick')
                            @include('filament.pages.invoice-import.pick')
                        @else
                            @include('filament.pages.invoice-import.confirm')
                        @endif
                    </section>
                @endif
            </div>
        @endif
    </div>
</x-filament-panels::page>
