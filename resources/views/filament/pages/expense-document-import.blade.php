@php
    $locale = app()->getLocale();
    $dateFormat = $locale === 'pt_PT' ? 'd/m/Y' : 'm/d/Y';
    $uploadIcon = '<svg class="fap-dropzone-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 15V4M7 9l5-5 5 5M4 20h16"/></svg>';
@endphp

<x-filament-panels::page>
    {{-- As in the mock: the two ways in side by side, the QR code of one invoice and a file of many. --}}
    <div class="grid gap-6 lg:grid-cols-2">
        <x-filament::section :heading="__('Invoice QR code')">
            <div class="space-y-4" x-data="{ pasting: {{ $errors->has('qrText') ? 'true' : 'false' }} }">
                <label class="fap-dropzone" x-on:livewire-upload-finish="$wire.previewQrImage()">
                    <input id="expense-qr-photo" type="file" wire:model="qrImage" accept="image/jpeg,image/png,image/webp" aria-describedby="expense-qr-help">
                    {!! $uploadIcon !!}
                    <span>
                        {{ __('Take a photo of the QR code or') }}
                        <button type="button" x-on:click.prevent="pasting = true; $nextTick(() => $refs.qrText.focus())">{{ __('paste its text') }}</button>
                    </span>
                    <span id="expense-qr-help" class="fap-dropzone-help">{{ __('Reads the supplier\'s tax ID, the date, the net amount and the VAT.') }}</span>
                    <span class="fap-dropzone-help" wire:loading wire:target="qrImage,previewQrImage">{{ __('Reading the photo…') }}</span>
                </label>
                @error('qrImage') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror

                <form wire:submit="previewQr" class="space-y-3" x-show="pasting" x-cloak>
                    <div class="space-y-1">
                        <label for="expense-qr-text" class="text-sm font-medium">{{ __('QR code contents') }}</label>
                        <x-filament::input.wrapper :valid="! $errors->has('qrText')">
                            <textarea id="expense-qr-text" x-ref="qrText" class="w-full px-3 py-2 text-sm" wire:model="qrText"
                                maxlength="{{ \App\Support\ExpenseDocumentQr::MAX_LENGTH }}" rows="3" required></textarea>
                        </x-filament::input.wrapper>
                        @error('qrText') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <x-filament::button type="submit" color="gray" size="sm" wire:loading.attr="disabled" wire:target="previewQr">
                        {{ __('Preview QR code') }}
                    </x-filament::button>
                </form>
            </div>
        </x-filament::section>

        <x-filament::section :heading="__('Received invoices file')">
            <form wire:submit="preview" class="space-y-4">
                <div class="space-y-1">
                    <label for="expense-document-origin" class="text-sm font-medium">{{ __('Origin') }}</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="expense-document-origin" wire:model.live="origin">
                            <option value="efatura">{{ __('Portal das Finanças (e-Fatura)') }}</option>
                            <option value="csv">{{ __('Another CSV, from one supplier') }}</option>
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>

                @if ($origin === 'csv')
                    <div class="space-y-1">
                        <label for="expense-document-source" class="text-sm font-medium">{{ __('Supplier tax ID') }}</label>
                        <x-filament::input.wrapper :valid="! $errors->has('source')">
                            <x-filament::input id="expense-document-source" type="text" wire:model="source"
                                :placeholder="__('Supplier tax ID')" maxlength="120" required />
                        </x-filament::input.wrapper>
                        @error('source') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                    </div>
                @endif

                <label class="fap-dropzone" x-on:livewire-upload-finish="$wire.preview()">
                    <input id="expense-document-file" type="file" wire:model="file" accept=".csv" aria-describedby="expense-document-help">
                    {!! $uploadIcon !!}
                    @if ($file)
                        <span class="fap-dropzone-file">{{ $file->getClientOriginalName() }}</span>
                        <span class="fap-dropzone-help"><span class="fap-dropzone-link">{{ __('Choose another file') }}</span></span>
                    @else
                        <span>{{ __('Drag the CSV here or') }} <span class="fap-dropzone-link">{{ __('choose a file') }}</span></span>
                    @endif
                    <span id="expense-document-help" class="fap-dropzone-help">{{ $origin === 'efatura'
                        ? __('In e-Fatura: Consult invoices › Purchaser › Export. Every supplier in one file.')
                        : __('One UTF-8 CSV per source, up to 100 invoices and 256 KB.') }}</span>
                </label>
                @error('file') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror

                @if ($file && $rows === [])
                    <x-filament::button type="submit" color="gray" size="sm" wire:loading.attr="disabled" wire:target="file,preview,confirm">
                        {{ __('Read file') }}
                    </x-filament::button>
                @endif

                <details class="text-sm text-gray-600 dark:text-gray-300" {!! $origin === 'efatura' ? 'hidden' : '' !!}>
                    <summary class="cursor-pointer font-medium">{{ __('File format') }}</summary>
                    <div class="mt-2 space-y-2">
                        <p>{{ __('Use dates in YYYY-MM-DD or DD/MM/YYYY and amounts in euros with a decimal comma.') }}</p>
                        <pre class="overflow-x-auto rounded-lg bg-gray-50 p-3 dark:bg-white/5">{{ \App\Support\ExpenseDocumentCsv::HEADER }}
2026-11-10;FT DEMO V2/2;20,00;4,60;24,60</pre>
                    </div>
                </details>
            </form>
        </x-filament::section>
    </div>

    @php
        $canConfirm = $this::canConfirm();
        $hasNewRows = in_array('new', array_column($candidates, 'state'), true);
    @endphp

        <div class="space-y-6" wire:dirty.remove wire:target="method,source,file,qrText,qrImage">
            @include('filament.pages.partials.tax-summary', ['stats' => $summary, 'notice' => null])

            <x-filament::section :heading="__('Preview of the invoices')">
                @if ($canConfirm && $hasNewRows)
                    <x-slot name="afterHeader">
                        <x-filament::button type="submit" form="expense-document-confirm" size="sm" :disabled="$chosenCount === 0"
                            wire:loading.attr="disabled" wire:target="file,qrImage,preview,previewQr,previewQrImage,confirm">
                            {{ trans_choice('{0} Import invoices|{1} Import 1 invoice|[2,*] Import :count invoices', $chosenCount) }}
                        </x-filament::button>
                    </x-slot>
                @endif

                <form id="expense-document-confirm" wire:submit="confirm" class="space-y-4">
                    @if ($rows !== [])
                    <p class="text-sm text-gray-600 dark:text-gray-300">
                        {{ $canConfirm ? __('Choose the expense for each invoice. Suggested expenses have the same date and amounts; suggested transactions are unprocessed debits for the same total, up to 30 days after the invoice.') : __('Preview only for this account.') }}
                    </p>
                    @endif

                    <div class="-mx-6 overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-y border-gray-200 bg-gray-50 text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-300">
                                    <th scope="col" class="px-6 py-2.5 text-start font-medium">{{ __('Invoice') }}</th>
                                    <th scope="col" class="px-3 py-2.5 text-start font-medium">{{ __('Supplier') }}</th>
                                    <th scope="col" class="px-3 py-2.5 text-end font-medium">{{ __('Total') }}</th>
                                    <th scope="col" class="px-3 py-2.5 text-start font-medium">{{ __('Match') }}</th>
                                    <th scope="col" class="px-3 py-2.5 text-start font-medium">{{ __('Expense in Contiu') }}</th>
                                    <th scope="col" class="px-3 py-2.5 pe-6 text-start font-medium">{{ __('Bank transaction') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                                @if ($rows === [])
                                    <tr>
                                        <td colspan="6" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">{{ __('No invoices read yet. Take a photo of a QR code or choose a file above.') }}</td>
                                    </tr>
                                @endif
                                @foreach ($rows as $index => $row)
                                    @php
                                        $candidate = $candidates[$index];
                                        $isNew = $candidate['state'] === 'new';
                                        [$matchLabel, $matchColor, $matchNote] = match (true) {
                                            $candidate['state'] === 'imported' => [__('Already imported'), 'success', null],
                                            $candidate['state'] === 'conflict' => [__('Conflict'), 'warning', __('Reference already imported with different details')],
                                            $candidate['expense_match'] === 'exact' => [__('Exact'), 'success', null],
                                            $candidate['expense_match'] === 'none' => [__('No candidates'), 'gray', null],
                                            $candidate['expense_match'] === 'limited' => [__('Awaiting confirmation'), 'warning', __('Several candidates. Showing up to 20.')],
                                            default => [__('Awaiting confirmation'), 'warning', trans_choice('{1} 1 possible expense|[2,*] :count possible expenses', count($candidate['expenses']))],
                                        };
                                    @endphp
                                    <tr wire:key="expense-document-{{ $index }}" class="align-top">
                                        <td class="px-6 py-3">
                                            <span class="block font-medium text-gray-950 dark:text-white">{{ $row['number'] }}</span>
                                            <span class="block text-gray-500 dark:text-gray-400">
                                                {{ \Carbon\CarbonImmutable::parse($row['document_date'])->format($dateFormat) }}
                                                @if (filled($row['atcud'] ?? null)) · ATCUD {{ $row['atcud'] }} @endif
                                            </span>
                                        </td>
                                        <td class="px-3 py-3">
                                            {{ $row['party_name'] ?? $previewSupplier ?? $previewSource }}
                                            @isset($row['party_tax_id'])
                                                <span class="block text-gray-500 dark:text-gray-400">{{ __('Tax ID :number', ['number' => $row['party_tax_id']]) }}</span>
                                            @endisset
                                        </td>
                                        <td class="px-3 py-3 text-end whitespace-nowrap tabular-nums">
                                            {{ \App\Support\Money::format($row['total_amount'], $locale) }}
                                            <span class="block text-gray-500 dark:text-gray-400">{{ __('VAT :amount', ['amount' => \App\Support\Money::format($row['vat_amount'], $locale)]) }}</span>
                                        </td>
                                        <td class="px-3 py-3">
                                            <x-filament::badge :color="$matchColor" class="w-fit">{{ $matchLabel }}</x-filament::badge>
                                            @if ($matchNote)
                                                <span class="mt-1 block text-gray-500 dark:text-gray-400">{{ $matchNote }}</span>
                                            @endif
                                        </td>
                                        <td class="min-w-56 px-3 py-3">
                                            @if ($isNew)
                                                <label for="expense-document-expense-{{ $index }}" class="sr-only">{{ __('Expense for :invoice', ['invoice' => $row['number']]) }}</label>
                                                <x-filament::input.wrapper>
                                                    <x-filament::input.select id="expense-document-expense-{{ $index }}"
                                                        wire:model.live="choices.{{ $index }}.expense" :disabled="! $canConfirm">
                                                        <option value="">{{ __('Skip import') }}</option>
                                                        <option value="unlinked">{{ __('Save without linking') }}</option>
                                                        @foreach ($candidate['expenses'] as $id => $label)
                                                            <option value="{{ $id }}">{{ $label }}</option>
                                                        @endforeach
                                                    </x-filament::input.select>
                                                </x-filament::input.wrapper>
                                                @if ($candidate['expenses'] === [])
                                                    <span class="mt-1 block text-gray-500 dark:text-gray-400">{{ __('No similar expense') }}</span>
                                                @endif
                                            @else
                                                <span class="text-gray-400 dark:text-gray-500">—</span>
                                            @endif
                                        </td>
                                        <td class="min-w-56 px-3 py-3 pe-6">
                                            @if ($isNew && $candidate['banks'] !== [])
                                                <label for="expense-document-bank-{{ $index }}" class="sr-only">{{ __('Bank transaction for :invoice', ['invoice' => $row['number']]) }}</label>
                                                <x-filament::input.wrapper>
                                                    <x-filament::input.select id="expense-document-bank-{{ $index }}"
                                                        wire:model="choices.{{ $index }}.bank" :disabled="! $canConfirm || $candidate['expenses'] === []">
                                                        <option value="">{{ __('Reconciliation pending') }}</option>
                                                        @foreach ($candidate['banks'] as $id => $label)
                                                            <option value="{{ $id }}">{{ $label }}</option>
                                                        @endforeach
                                                    </x-filament::input.select>
                                                </x-filament::input.wrapper>
                                            @else
                                                <span class="text-gray-500 dark:text-gray-400">{{ $isNew ? __('No transaction') : '—' }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @foreach (array_unique([...$errors->get('choices'), ...collect($errors->get('choices.*'))->flatten()->all()]) as $error)
                        <p class="text-sm text-danger-600" role="alert">{{ $error }}</p>
                    @endforeach
                </form>
            </x-filament::section>
        </div>
</x-filament-panels::page>
