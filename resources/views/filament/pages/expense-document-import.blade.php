<x-filament-panels::page>
    <x-filament::tabs :label="__('Import method')">
        <x-filament::tabs.item :active="$method === 'qr'" wire:click="$set('method', 'qr')">
            {{ __('Scan QR code') }}
        </x-filament::tabs.item>
        <x-filament::tabs.item :active="$method !== 'qr'" wire:click="$set('method', 'csv')">
            {{ __('Import CSV') }}
        </x-filament::tabs.item>
    </x-filament::tabs>

    @if ($method === 'qr')
        <x-filament::section :heading="__('Invoice QR code')">
            <div class="grid gap-6 lg:grid-cols-2">
                <form wire:submit="previewQrImage" class="space-y-4">
                    <div class="space-y-1">
                        <label for="expense-qr-camera" class="text-sm font-medium">{{ __('Take photo') }}</label>
                        <x-filament::input.wrapper>
                            <x-filament::input id="expense-qr-camera" type="file" wire:model="qrImage"
                                accept="image/jpeg,image/png,image/webp" capture="environment" />
                        </x-filament::input.wrapper>
                    </div>
                    <div class="space-y-1">
                        <label for="expense-qr-photo" class="text-sm font-medium">{{ __('Choose photo') }}</label>
                        <x-filament::input.wrapper>
                            <x-filament::input id="expense-qr-photo" type="file" wire:model="qrImage"
                                accept="image/jpeg,image/png,image/webp" />
                        </x-filament::input.wrapper>
                        @error('qrImage') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="qrImage,previewQrImage">
                        {{ __('Read photo') }}
                    </x-filament::button>
                </form>

                <form wire:submit="previewQr" class="space-y-4">
                    <div class="space-y-1">
                        <label for="expense-qr-text" class="text-sm font-medium">{{ __('QR code contents') }}</label>
                        <x-filament::input.wrapper :valid="! $errors->has('qrText')">
                            <textarea id="expense-qr-text" class="w-full px-3 py-2 text-sm" wire:model="qrText"
                                maxlength="{{ \App\Support\ExpenseDocumentQr::MAX_LENGTH }}" rows="4" required></textarea>
                        </x-filament::input.wrapper>
                        @error('qrText') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="previewQr">
                        {{ __('Preview QR code') }}
                    </x-filament::button>
                </form>
            </div>
        </x-filament::section>
    @else
    <x-filament::section :heading="__('Received invoices file')">
        <form wire:submit="preview" class="space-y-4">
            <div class="space-y-1 text-sm text-gray-600 dark:text-gray-300">
                <p>{{ __('Use one UTF-8 CSV per source, for example one per supplier. Limit of 100 invoices and 256 KB.') }}</p>
                <p>{{ __('Use dates in YYYY-MM-DD or DD/MM/YYYY and amounts in euros with a decimal comma.') }}</p>
                <pre class="overflow-x-auto rounded-lg bg-gray-50 p-3 dark:bg-white/5">{{ \App\Support\ExpenseDocumentCsv::HEADER }}
2026-11-10;FT DEMO V2/2;20,00;4,60;24,60</pre>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="space-y-1">
                    <label for="expense-document-source" class="text-sm font-medium">{{ __('Source') }}</label>
                    <x-filament::input.wrapper :valid="! $errors->has('source')">
                        <x-filament::input id="expense-document-source" type="text" wire:model="source"
                            :placeholder="__('Supplier tax ID')" maxlength="120" required />
                    </x-filament::input.wrapper>
                    @error('source')
                        <p class="text-sm text-danger-600" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div class="space-y-1">
                    <label for="expense-document-file" class="text-sm font-medium">{{ __('CSV file') }}</label>
                    <x-filament::input.wrapper :valid="! $errors->has('file')">
                        <x-filament::input id="expense-document-file" type="file" wire:model="file" accept=".csv" required />
                    </x-filament::input.wrapper>
                    @error('file')
                        <p class="text-sm text-danger-600" role="alert">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="file,preview,confirm">
                {{ __('Preview') }}
            </x-filament::button>
        </form>
    </x-filament::section>
    @endif

    @if ($rows !== [])
        @php
            $canConfirm = $this::canConfirm();
            $hasNewRows = in_array('new', array_column($candidates, 'state'), true);
            $matchLabels = [
                'exact' => __('Exact match'),
                'ambiguous' => __('Match pending confirmation'),
                'none' => __('No candidates'),
                'limited' => __('Several candidates. Showing up to 20.'),
            ];
        @endphp

        @include('filament.pages.partials.tax-summary', ['stats' => $summary, 'notice' => null])

        <x-filament::section :heading="__('Preview results')">
            <form wire:submit="confirm" class="space-y-4">
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    {!! __('Source :source. Suggested expenses have the same date and amounts. Suggested transactions are unprocessed debits for the same total, dated from the invoice date to 30 days later.', ['source' => '<strong>'.e($previewSource).'</strong>']) !!}
                </p>

                @if (! $canConfirm)
                    <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('Preview only for this account.') }}</p>
                @endif

                <ul class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($rows as $index => $row)
                        @php($candidate = $candidates[$index])
                        <li wire:key="expense-document-{{ $index }}" class="space-y-3 py-4">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h3 class="text-sm font-semibold">{{ $row['number'] }}</h3>
                                <p class="text-sm text-gray-600 dark:text-gray-300">
                                    {{ \Carbon\CarbonImmutable::parse($row['document_date'])->format(app()->getLocale() === 'pt_PT' ? 'd/m/Y' : 'm/d/Y') }}
                                    · {{ __('Net :amount', ['amount' => \App\Support\Money::format($row['net_amount'], app()->getLocale())]) }}
                                    · {{ __('VAT :amount', ['amount' => \App\Support\Money::format($row['vat_amount'], app()->getLocale())]) }}
                                    · <strong>{{ __('Total :amount', ['amount' => \App\Support\Money::format($row['total_amount'], app()->getLocale())]) }}</strong>
                                </p>
                            </div>
                            @isset($row['party_tax_id'])
                                <p class="text-sm text-gray-600 dark:text-gray-300">
                                    @if (filled($row['party_name'] ?? null)) {{ $row['party_name'] }} · @endif
                                    {{ __('Tax ID :number', ['number' => $row['party_tax_id']]) }}
                                    @if (filled($row['atcud'] ?? null)) · ATCUD {{ $row['atcud'] }} @endif
                                </p>
                            @endisset

                            @if ($candidate['state'] === 'imported')
                                <x-filament::badge color="success">{{ __('Already imported') }}</x-filament::badge>
                            @elseif ($candidate['state'] === 'conflict')
                                <x-filament::badge color="warning">{{ __('Reference already imported with different details') }}</x-filament::badge>
                            @else
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div class="space-y-1">
                                        <label for="expense-document-expense-{{ $index }}" class="text-sm font-medium">{{ __('Expense') }}</label>
                                        <p class="text-xs text-gray-600 dark:text-gray-300">{{ $matchLabels[$candidate['expense_match']] }}</p>
                                        <x-filament::input.wrapper>
                                            <x-filament::input.select id="expense-document-expense-{{ $index }}"
                                                wire:model="choices.{{ $index }}.expense" :disabled="! $canConfirm">
                                                <option value="">{{ __('Skip import') }}</option>
                                                <option value="unlinked">{{ __('Save without linking') }}</option>
                                                @foreach ($candidate['expenses'] as $id => $label)
                                                    <option value="{{ $id }}">{{ $label }}</option>
                                                @endforeach
                                            </x-filament::input.select>
                                        </x-filament::input.wrapper>
                                    </div>

                                    <div class="space-y-1">
                                        <label for="expense-document-bank-{{ $index }}" class="text-sm font-medium">{{ __('Bank transaction (optional)') }}</label>
                                        <p class="text-xs text-gray-600 dark:text-gray-300">{{ $matchLabels[$candidate['bank_match']] }}</p>
                                        <x-filament::input.wrapper>
                                            <x-filament::input.select id="expense-document-bank-{{ $index }}"
                                                wire:model="choices.{{ $index }}.bank" :disabled="! $canConfirm || $candidate['expenses'] === [] || $candidate['banks'] === []">
                                                <option value="">{{ __('Reconciliation pending') }}</option>
                                                @foreach ($candidate['banks'] as $id => $label)
                                                    <option value="{{ $id }}">{{ $label }}</option>
                                                @endforeach
                                            </x-filament::input.select>
                                        </x-filament::input.wrapper>
                                    </div>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>

                @foreach (array_unique([...$errors->get('choices'), ...collect($errors->get('choices.*'))->flatten()->all()]) as $error)
                    <p class="text-sm text-danger-600" role="alert">{{ $error }}</p>
                @endforeach

                @if ($canConfirm && $hasNewRows)
                    <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="file,preview,confirm">
                        {{ __('Confirm import') }}
                    </x-filament::button>
                @endif
            </form>
        </x-filament::section>
    @endif
</x-filament-panels::page>
