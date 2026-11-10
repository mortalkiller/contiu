@use(App\Enums\FiscalDocumentStatus)
@use(App\Enums\FiscalDocumentType)
@use(App\Support\InvoiceXpressClient)
@use(App\Support\Money)

<x-filament-panels::page>
    <x-filament::section heading="Consultar documentos emitidos">
        @if ($this->configuredSubdomain === null)
            <p class="text-sm text-gray-600 dark:text-gray-400">Configura a ligação ao InvoiceXpress em <x-filament::link :href="\App\Filament\Pages\InvoiceXpressSettingsPage::getUrl()">Configuração</x-filament::link> para consultar documentos.</p>
        @else
            <p class="text-sm text-gray-600 dark:text-gray-400">
                Conta {{ $this->configuredSubdomain }}. Seleciona até 366 dias e confirma cada documento que queres registar no Contiu.
            </p>

            <form wire:submit="preview" class="mt-4 space-y-3">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="space-y-1">
                        <label for="ix-from" class="block text-sm font-medium">Data inicial</label>
                        <x-filament::input.wrapper>
                            <x-filament::input type="date" id="ix-from" wire:model.live="from" required />
                        </x-filament::input.wrapper>
                    </div>
                    <div class="space-y-1">
                        <label for="ix-to" class="block text-sm font-medium">Data final</label>
                        <x-filament::input.wrapper>
                            <x-filament::input type="date" id="ix-to" wire:model.live="to" required />
                        </x-filament::input.wrapper>
                    </div>
                    <label class="flex items-center gap-2 py-2 text-sm">
                        <x-filament::input.checkbox wire:model.live="archived" />
                        Incluir arquivados
                    </label>
                    <x-filament::button type="submit" wire:loading.attr="disabled">Consultar</x-filament::button>
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

        <x-filament::section heading="Rever documentos">
            @if ($omitted > 0)
                <p class="mb-3 text-sm text-gray-600 dark:text-gray-400">
                    {{ $omitted }} {{ $omitted === 1 ? 'documento omitido' : 'documentos omitidos' }} nesta página por dados inválidos, tipo, estado, moeda ou data fora do âmbito.
                </p>
            @endif

            @if ($rows === [])
                <p class="text-sm text-gray-600 dark:text-gray-400">Sem documentos elegíveis nesta página.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-white/10">
                                <th scope="col" class="py-2 text-start font-medium">Documento</th>
                                <th scope="col" class="px-3 py-2 text-start font-medium">Cliente</th>
                                <th scope="col" class="px-3 py-2 text-end font-medium">Total</th>
                                <th scope="col" class="py-2 text-end font-medium">Registo no Contiu</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach ($rows as $index => $row)
                                @php
                                    $document = $row['after'];
                                    $before = $row['before'];
                                @endphp
                                <tr wire:key="ix-document-{{ $index }}">
                                    <td class="py-3 align-top">
                                        <div class="font-medium">{{ $document['number'] }}</div>
                                        <div class="text-gray-600 dark:text-gray-400">
                                            {{ FiscalDocumentType::from($document['type'])->getLabel() }}
                                            · {{ \Carbon\CarbonImmutable::parse($document['document_date'])->format('d/m/Y') }}
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
                                        <div class="font-medium tabular-nums">{{ Money::format($document['total_amount']) }}</div>
                                        <div class="text-gray-600 dark:text-gray-400">Base {{ Money::format($document['net_amount']) }}</div>
                                        <div class="text-gray-600 dark:text-gray-400">IVA {{ Money::format($document['vat_amount']) }}</div>
                                    </td>
                                    <td class="py-3 text-end align-top">
                                        @if ($row['state'] === 'same')
                                            <span class="text-gray-600 dark:text-gray-400">Registado</span>
                                        @elseif ($row['state'] === 'conflict')
                                            <span class="text-warning-600 dark:text-warning-400">Conflito com o registo local</span>
                                        @else
                                            {{ ($this->importDocumentAction)(['index' => $index, 'review' => $row['review_key']]) }}
                                            @if (count($row['suggestions']) === 1)
                                                <div class="mt-1 text-gray-600 dark:text-gray-400">Sugestão · {{ reset($row['suggestions']) }}</div>
                                            @elseif (count($row['suggestions']) > 1)
                                                <div class="mt-1 text-warning-600 dark:text-warning-400">Várias cobranças possíveis</div>
                                            @endif
                                        @endif
                                    </td>
                                </tr>

                                @if ($row['state'] === 'changed' && $before !== null)
                                    <tr wire:key="ix-correction-{{ $index }}">
                                        <td colspan="4" class="py-3">
                                            <div class="font-medium">Registo atual no Contiu</div>
                                            <p class="text-gray-600 dark:text-gray-400">
                                                {{ $before['number'] }}
                                                · {{ FiscalDocumentType::from($before['type'])->getLabel() }}
                                                · {{ \Carbon\CarbonImmutable::parse($before['document_date'])->format('d/m/Y') }}
                                                · {{ FiscalDocumentStatus::from($before['status'])->getLabel() }}
                                                · {{ $before['party_name'] ?? '—' }}
                                                @if ($before['party_tax_id'])
                                                    · {{ $before['party_tax_id'] }}
                                                @endif
                                                · Base {{ Money::format($before['net_amount']) }}
                                                · IVA {{ Money::format($before['vat_amount']) }}
                                                · Total {{ Money::format($before['total_amount']) }}
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
                <nav class="mt-4 flex flex-wrap items-center gap-3 text-sm" aria-label="Páginas de documentos">
                    <x-filament::button color="gray" size="sm" wire:click="movePage(-1)" :disabled="$previewScope['page'] <= 1" wire:loading.attr="disabled">
                        Anterior
                    </x-filament::button>
                    <span>Página {{ $previewScope['page'] }} de {{ $previewScope['totalPages'] }}</span>
                    <x-filament::button color="gray" size="sm" wire:click="movePage(1)"
                        :disabled="$previewScope['page'] >= min($previewScope['totalPages'], InvoiceXpressClient::MAX_PAGES)" wire:loading.attr="disabled">
                        Seguinte
                    </x-filament::button>
                </nav>
            @endif

            @if ($previewScope['totalPages'] > InvoiceXpressClient::MAX_PAGES)
                <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">Reduz o intervalo para aceder a resultados além das primeiras 25 páginas.</p>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
