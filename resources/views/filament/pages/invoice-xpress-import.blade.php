@use(App\Enums\FiscalDocumentStatus)
@use(App\Enums\FiscalDocumentType)
@use(App\Support\InvoiceXpressClient)
@use(App\Support\Money)

<x-filament-panels::page>
    <x-filament::section :heading="__('Look up issued documents')">
        @if ($this->configuredSubdomain === null)
            @php
                [$beforeConfiguration, $afterConfiguration] = explode(':configuration', __('Set up the InvoiceXpress connection in :configuration to look up documents.'), 2);
            @endphp
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ $beforeConfiguration }}<x-filament::link :href="\App\Filament\Pages\InvoiceXpressSettingsPage::getUrl()">{{ __('Configuration') }}</x-filament::link>{{ $afterConfiguration }}</p>
        @else
            <p class="text-sm text-gray-600 dark:text-gray-400">
                {{ __('Account :account. Select up to 366 days and confirm each document you want to record in Contiu.', ['account' => $this->configuredSubdomain]) }}
            </p>

            <form wire:submit="preview" class="mt-4 space-y-3">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="space-y-1">
                        <label for="ix-from" class="block text-sm font-medium">{{ __('Start date') }}</label>
                        <x-filament::input.wrapper>
                            <x-filament::input type="date" id="ix-from" wire:model.live="from" required />
                        </x-filament::input.wrapper>
                    </div>
                    <div class="space-y-1">
                        <label for="ix-to" class="block text-sm font-medium">{{ __('End date') }}</label>
                        <x-filament::input.wrapper>
                            <x-filament::input type="date" id="ix-to" wire:model.live="to" required />
                        </x-filament::input.wrapper>
                    </div>
                    <label class="flex items-center gap-2 py-2 text-sm">
                        <x-filament::input.checkbox wire:model.live="archived" />
                        {{ __('Include archived') }}
                    </label>
                    <x-filament::button type="submit" wire:loading.attr="disabled">{{ __('Look up') }}</x-filament::button>
                </div>
                @foreach (['to', 'page', 'provider'] as $field)
                    @error($field)
                        <p class="text-sm text-danger-600 dark:text-danger-400" role="alert">{{ $message }}</p>
                    @enderror
                @endforeach
            </form>
        @endif
    </x-filament::section>

    @if ($previewScope !== null)
        @include('filament.pages.partials.tax-summary', ['stats' => $summary, 'notice' => null])

        <x-filament::section :heading="__('Review documents')">
            @if ($omitted > 0)
                <p class="mb-3 text-sm text-gray-600 dark:text-gray-400">
                    {{ trans_choice('{1} 1 document omitted on this page because of invalid data or an out-of-scope type, status, currency or date.|[2,*] :count documents omitted on this page because of invalid data or an out-of-scope type, status, currency or date.', $omitted) }}
                </p>
            @endif

            @if ($rows === [])
                <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('No eligible documents on this page.') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-white/10">
                                <th scope="col" class="py-2 text-start font-medium">{{ __('Document') }}</th>
                                <th scope="col" class="px-3 py-2 text-start font-medium">{{ __('Client') }}</th>
                                <th scope="col" class="px-3 py-2 text-end font-medium">{{ __('Total') }}</th>
                                <th scope="col" class="py-2 text-end font-medium">{{ __('Record in Contiu') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach ($rows as $index => $row)
                                @php
                                    $document = $row['after'];
                                    $before = $row['before'];
                                    $withholding = $row['withholding'] ?? null;
                                @endphp
                                <tr wire:key="ix-document-{{ $index }}">
                                    <td class="py-3 align-top">
                                        <div class="font-medium">{{ $document['number'] }}</div>
                                        <div class="text-gray-600 dark:text-gray-400">
                                            {{ FiscalDocumentType::from($document['type'])->getLabel() }}
                                            · {{ \Carbon\CarbonImmutable::parse($document['document_date'])->format(app()->getLocale() === 'pt_PT' ? 'd/m/Y' : 'm/d/Y') }}
                                            · {{ FiscalDocumentStatus::from($document['status'])->getLabel() }}
                                        </div>
                                    </td>
                                    <td class="px-3 py-3 align-top">
                                        {{ $document['party_name'] ?? '—' }}
                                        @if ($document['party_tax_id'])
                                            <div class="text-gray-600 dark:text-gray-400">{{ $document['party_tax_id'] }}</div>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3 text-end align-top whitespace-nowrap">
                                        <div class="font-medium tabular-nums">{{ $withholding === null ? Money::format($document['total_amount'], app()->getLocale()) : __('Gross :amount', ['amount' => Money::format($document['total_amount'], app()->getLocale())]) }}</div>
                                        <div class="text-gray-600 dark:text-gray-400">{{ __('Net amount :amount', ['amount' => Money::format($document['net_amount'], app()->getLocale())]) }}</div>
                                        <div class="text-gray-600 dark:text-gray-400">{{ __('VAT :amount', ['amount' => Money::format($document['vat_amount'], app()->getLocale())]) }}</div>
                                        @if ($withholding !== null)
                                            <div class="text-gray-600 dark:text-gray-400">{{ __('Withholding tax :amount', ['amount' => Money::format($withholding['retained'], app()->getLocale())]) }}</div>
                                            <div>{{ __('Net :amount', ['amount' => Money::format($withholding['cash'], app()->getLocale())]) }}</div>
                                        @endif
                                    </td>
                                    <td class="py-3 text-end align-top">
                                        @if ($row['state'] === 'same')
                                            <span class="text-gray-600 dark:text-gray-400">{{ __('Recorded') }}</span>
                                        @elseif ($row['state'] === 'conflict')
                                            <span class="text-warning-600 dark:text-warning-400">{{ __('Conflict with local record') }}</span>
                                        @else
                                            {{ ($this->importDocumentAction)(['index' => $index, 'review' => $row['review_key']]) }}
                                            @if (count($row['suggestions']) === 1)
                                                <div class="mt-1 text-gray-600 dark:text-gray-400">{{ __('Suggestion · :suggestion', ['suggestion' => reset($row['suggestions'])]) }}</div>
                                            @elseif (count($row['suggestions']) > 1)
                                                <div class="mt-1 text-warning-600 dark:text-warning-400">{{ __('Several possible charges') }}</div>
                                            @endif
                                        @endif
                                    </td>
                                </tr>

                                @if ($row['state'] === 'changed' && $before !== null)
                                    <tr wire:key="ix-correction-{{ $index }}">
                                        <td colspan="4" class="py-3">
                                            <div class="font-medium">{{ __('Current record in Contiu') }}</div>
                                            <p class="text-gray-600 dark:text-gray-400">
                                                {{ $before['number'] }}
                                                · {{ FiscalDocumentType::from($before['type'])->getLabel() }}
                                                · {{ \Carbon\CarbonImmutable::parse($before['document_date'])->format(app()->getLocale() === 'pt_PT' ? 'd/m/Y' : 'm/d/Y') }}
                                                · {{ FiscalDocumentStatus::from($before['status'])->getLabel() }}
                                                · {{ $before['party_name'] ?? '—' }}
                                                @if ($before['party_tax_id'])
                                                    · {{ $before['party_tax_id'] }}
                                                @endif
                                                · {{ __('Net amount :amount', ['amount' => Money::format($before['net_amount'], app()->getLocale())]) }}
                                                · {{ __('VAT :amount', ['amount' => Money::format($before['vat_amount'], app()->getLocale())]) }}
                                                · {{ __('Total :amount', ['amount' => Money::format($before['total_amount'], app()->getLocale())]) }}
                                            </p>
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($previewScope['totalPages'] > 1)
                <nav class="mt-4 flex flex-wrap items-center gap-3 text-sm" aria-label="{{ __('Document pages') }}">
                    <x-filament::button color="gray" size="sm" wire:click="movePage(-1)" :disabled="$previewScope['page'] <= 1" wire:loading.attr="disabled">
                        {{ __('Previous') }}
                    </x-filament::button>
                    <span>{{ __('Page :page of :total', ['page' => $previewScope['page'], 'total' => $previewScope['totalPages']]) }}</span>
                    <x-filament::button color="gray" size="sm" wire:click="movePage(1)"
                        :disabled="$previewScope['page'] >= min($previewScope['totalPages'], InvoiceXpressClient::MAX_PAGES)" wire:loading.attr="disabled">
                        {{ __('Next') }}
                    </x-filament::button>
                </nav>
            @endif

            @if ($previewScope['totalPages'] > InvoiceXpressClient::MAX_PAGES)
                <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">{{ __('Narrow the date range to access results beyond the first 25 pages.') }}</p>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
