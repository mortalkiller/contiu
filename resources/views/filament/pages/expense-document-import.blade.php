<x-filament-panels::page>
    <x-filament::tabs label="Método de importação">
        <x-filament::tabs.item :active="$method === 'qr'" wire:click="$set('method', 'qr')">
            Ler código QR
        </x-filament::tabs.item>
        <x-filament::tabs.item :active="$method !== 'qr'" wire:click="$set('method', 'csv')">
            Importar CSV
        </x-filament::tabs.item>
    </x-filament::tabs>

    @if ($method === 'qr')
        <x-filament::section heading="Código QR da fatura">
            <div class="grid gap-6 lg:grid-cols-2">
                <form wire:submit="previewQrImage" class="space-y-4">
                    <div class="space-y-1">
                        <label for="expense-qr-camera" class="text-sm font-medium">Tirar fotografia</label>
                        <x-filament::input.wrapper>
                            <x-filament::input id="expense-qr-camera" type="file" wire:model="qrImage"
                                accept="image/jpeg,image/png,image/webp" capture="environment" />
                        </x-filament::input.wrapper>
                    </div>
                    <div class="space-y-1">
                        <label for="expense-qr-photo" class="text-sm font-medium">Escolher fotografia</label>
                        <x-filament::input.wrapper>
                            <x-filament::input id="expense-qr-photo" type="file" wire:model="qrImage"
                                accept="image/jpeg,image/png,image/webp" />
                        </x-filament::input.wrapper>
                        @error('qrImage') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="qrImage,previewQrImage">
                        Ler fotografia
                    </x-filament::button>
                </form>

                <form wire:submit="previewQr" class="space-y-4">
                    <div class="space-y-1">
                        <label for="expense-qr-text" class="text-sm font-medium">Conteúdo do código QR</label>
                        <x-filament::input.wrapper :valid="! $errors->has('qrText')">
                            <textarea id="expense-qr-text" class="w-full px-3 py-2 text-sm" wire:model="qrText"
                                maxlength="{{ \App\Support\ExpenseDocumentQr::MAX_LENGTH }}" rows="4" required></textarea>
                        </x-filament::input.wrapper>
                        @error('qrText') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="previewQr">
                        Pré-visualizar QR
                    </x-filament::button>
                </form>
            </div>
        </x-filament::section>
    @else
    <x-filament::section heading="Ficheiro de faturas recebidas">
        <form wire:submit="preview" class="space-y-4">
            <div class="space-y-1 text-sm text-gray-600 dark:text-gray-300">
                <p>Usa um CSV em UTF-8 por origem, por exemplo, um por fornecedor. Limite de 100 faturas e 256 KB.</p>
                <p>Usa datas AAAA-MM-DD ou DD/MM/AAAA e valores em euros com vírgula decimal.</p>
                <pre class="overflow-x-auto rounded-lg bg-gray-50 p-3 dark:bg-white/5">{{ \App\Support\ExpenseDocumentCsv::HEADER }}
2026-11-10;FT DEMO V2/2;20,00;4,60;24,60</pre>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="space-y-1">
                    <label for="expense-document-source" class="text-sm font-medium">Origem</label>
                    <x-filament::input.wrapper :valid="! $errors->has('source')">
                        <x-filament::input id="expense-document-source" type="text" wire:model="source"
                            placeholder="NIF do fornecedor" maxlength="120" required />
                    </x-filament::input.wrapper>
                    @error('source')
                        <p class="text-sm text-danger-600" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div class="space-y-1">
                    <label for="expense-document-file" class="text-sm font-medium">Ficheiro CSV</label>
                    <x-filament::input.wrapper :valid="! $errors->has('file')">
                        <x-filament::input id="expense-document-file" type="file" wire:model="file" accept=".csv" required />
                    </x-filament::input.wrapper>
                    @error('file')
                        <p class="text-sm text-danger-600" role="alert">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="file,preview,confirm">
                Pré-visualizar
            </x-filament::button>
        </form>
    </x-filament::section>
    @endif

    @if ($rows !== [])
        @php
            $canConfirm = $this::canConfirm();
            $hasNewRows = in_array('new', array_column($candidates, 'state'), true);
            $matchLabels = [
                'exact' => 'Correspondência exata',
                'ambiguous' => 'Correspondência por confirmar',
                'none' => 'Sem candidatos',
                'limited' => 'Vários candidatos. A mostrar até 20.',
            ];
        @endphp

        @include('filament.pages.partials.tax-summary', ['stats' => $summary, 'notice' => null])

        <x-filament::section heading="Pré-visualização">
            <form wire:submit="confirm" class="space-y-4">
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    Origem <strong>{{ $previewSource }}</strong>. As despesas sugeridas têm a mesma data e os mesmos valores.
                    Os movimentos sugeridos são débitos por tratar, pelo mesmo total, desde a data da fatura até 30 dias depois.
                </p>

                @if (! $canConfirm)
                    <p class="text-sm text-gray-600 dark:text-gray-300">Apenas pré-visualização nesta conta.</p>
                @endif

                <ul class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($rows as $index => $row)
                        @php($candidate = $candidates[$index])
                        <li wire:key="expense-document-{{ $index }}" class="space-y-3 py-4">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h3 class="text-sm font-semibold">{{ $row['number'] }}</h3>
                                <p class="text-sm text-gray-600 dark:text-gray-300">
                                    {{ \Carbon\CarbonImmutable::parse($row['document_date'])->format('d/m/Y') }}
                                    · Líquido {{ \App\Support\Money::format($row['net_amount']) }}
                                    · IVA {{ \App\Support\Money::format($row['vat_amount']) }}
                                    · <strong>Total {{ \App\Support\Money::format($row['total_amount']) }}</strong>
                                </p>
                            </div>
                            @isset($row['party_tax_id'])
                                <p class="text-sm text-gray-600 dark:text-gray-300">
                                    @if (filled($row['party_name'] ?? null)) {{ $row['party_name'] }} · @endif
                                    NIF {{ $row['party_tax_id'] }}
                                    @if (filled($row['atcud'] ?? null)) · ATCUD {{ $row['atcud'] }} @endif
                                </p>
                            @endisset

                            @if ($candidate['state'] === 'imported')
                                <x-filament::badge color="success">Já importada</x-filament::badge>
                            @elseif ($candidate['state'] === 'conflict')
                                <x-filament::badge color="warning">Referência já importada com outros dados</x-filament::badge>
                            @else
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div class="space-y-1">
                                        <label for="expense-document-expense-{{ $index }}" class="text-sm font-medium">Despesa</label>
                                        <p class="text-xs text-gray-600 dark:text-gray-300">{{ $matchLabels[$candidate['expense_match']] }}</p>
                                        <x-filament::input.wrapper>
                                            <x-filament::input.select id="expense-document-expense-{{ $index }}"
                                                wire:model="choices.{{ $index }}.expense" :disabled="! $canConfirm">
                                                <option value="">Deixar por importar</option>
                                                <option value="unlinked">Guardar sem associação</option>
                                                @foreach ($candidate['expenses'] as $id => $label)
                                                    <option value="{{ $id }}">{{ $label }}</option>
                                                @endforeach
                                            </x-filament::input.select>
                                        </x-filament::input.wrapper>
                                    </div>

                                    <div class="space-y-1">
                                        <label for="expense-document-bank-{{ $index }}" class="text-sm font-medium">Movimento bancário (opcional)</label>
                                        <p class="text-xs text-gray-600 dark:text-gray-300">{{ $matchLabels[$candidate['bank_match']] }}</p>
                                        <x-filament::input.wrapper>
                                            <x-filament::input.select id="expense-document-bank-{{ $index }}"
                                                wire:model="choices.{{ $index }}.bank" :disabled="! $canConfirm || $candidate['expenses'] === [] || $candidate['banks'] === []">
                                                <option value="">Sem conciliação</option>
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
                        Confirmar importação
                    </x-filament::button>
                @endif
            </form>
        </x-filament::section>
    @endif
</x-filament-panels::page>
