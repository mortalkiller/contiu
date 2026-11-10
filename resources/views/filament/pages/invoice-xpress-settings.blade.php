<x-filament-panels::page>
    <x-filament::section heading="Ligação ao InvoiceXpress">
        <p class="text-sm">
            {{ $subdomain === null ? 'Ligação por configurar.' : 'Conta '.$subdomain }}
        </p>
    </x-filament::section>

    @if ($canManagePilot)
        <x-filament::section heading="Configuração da emissão">
            @if ($subdomain !== null)
                <x-filament::button type="button" color="gray" wire:click="loadCatalog" wire:loading.attr="disabled">
                    {{ $catalogChoices === null ? 'Consultar catálogo do InvoiceXpress' : 'Atualizar catálogo' }}
                </x-filament::button>
                @error('catalog') <p class="mt-3 text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                @if (! $canWriteDocuments)
                    <p class="mt-3 text-sm">A emissão aguarda a validação de que a API preserva o catálogo do InvoiceXpress.</p>
                @endif
                @if ($catalogChoices !== null)
                <form wire:submit="saveSettings" class="mt-4 space-y-4">
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="space-y-1">
                            <label for="ix-approved-items" class="text-sm font-medium">Artigos autorizados</label>
                            <x-filament::input.wrapper>
                                <x-filament::input.select id="ix-approved-items" wire:model="approvedItems" multiple required>
                                    @foreach ($catalogChoices['items'] as $id => $name)
                                        <option value="{{ $id }}">{{ $name }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                            @error('approvedItems') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                        </div>
                        <div class="space-y-1">
                            <label for="ix-tax-name" class="text-sm font-medium">IVA</label>
                            <x-filament::input.wrapper>
                                <x-filament::input.select id="ix-tax-name" wire:model="taxName" required>
                                    <option value="">Escolher IVA</option>
                                    @foreach ($catalogChoices['taxes'] as $name)
                                        <option value="{{ $name }}">{{ $name }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                            @error('taxName') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                        </div>
                        <div class="space-y-1">
                            <label for="ix-sequence-id" class="text-sm font-medium">Série registada na AT</label>
                            <x-filament::input.wrapper>
                                <x-filament::input.select id="ix-sequence-id" wire:model="sequenceId" required>
                                    <option value="">Escolher série</option>
                                    @foreach ($catalogChoices['sequences'] as $id => $name)
                                        <option value="{{ $id }}">{{ $name }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                            @if ($catalogChoices['sequences'] === [])
                                <p class="text-sm">Regista uma série de faturas no InvoiceXpress para a poder selecionar aqui.</p>
                            @endif
                            @error('sequenceId') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    @if ($hasPendingAttempts)
                        <p class="text-sm">Retoma as tentativas pendentes antes de alterar a configuração de emissão.</p>
                    @endif
                    @error('settings') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                    <x-filament::button type="submit" :disabled="$hasPendingAttempts || $catalogChoices['sequences'] === []" wire:loading.attr="disabled">
                        {{ $options === null ? 'Selecionar InvoiceXpress para emitir' : 'Guardar configuração' }}
                    </x-filament::button>
                </form>
                @endif
            @else
                <p class="text-sm">Configura a ligação ao InvoiceXpress para selecionar os artigos, o IVA e a série.</p>
            @endif
        </x-filament::section>

    @endif
</x-filament-panels::page>
