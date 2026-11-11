<x-filament-panels::page>
    <x-filament::section :heading="__('InvoiceXpress connection')">
        <p class="text-sm">
            {{ $subdomain === null ? __('Connection not configured.') : __('Account :account', ['account' => $subdomain]) }}
        </p>
        @if ($subdomain !== null)
            <div class="mt-3 space-y-2">
                <x-filament::button type="button" color="gray" wire:click="testConnection" wire:loading.attr="disabled">
                    {{ __('Test connection') }}
                </x-filament::button>
                @error('connection') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                @if ($connectionSeries !== null)
                    <div class="text-sm" role="status">
                        <p class="font-medium">{{ __('Connection confirmed') }}</p>
                        <p>{{ $connectionSeries === [] ? __('No series in the account.') : __('Series found · :series', ['series' => implode(', ', $connectionSeries)]) }}</p>
                    </div>
                @endif
            </div>
        @endif
    </x-filament::section>

    @if ($canManagePilot)
        <x-filament::section :heading="__('Issuing settings')">
            @if ($subdomain !== null)
                <x-filament::button type="button" color="gray" wire:click="loadCatalog" wire:loading.attr="disabled">
                    {{ $catalogChoices === null ? __('Look up InvoiceXpress catalogue') : __('Refresh catalogue') }}
                </x-filament::button>
                @error('catalog') <p class="mt-3 text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                @if (! $canWriteDocuments)
                    <p class="mt-3 text-sm">{{ __('Issuing is pending confirmation that the API preserves the InvoiceXpress catalogue.') }}</p>
                @endif
                @if ($catalogChoices !== null)
                <form wire:submit="saveSettings" class="mt-4 space-y-4">
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="space-y-1">
                            <label for="ix-approved-items" class="text-sm font-medium">{{ __('Authorised items') }}</label>
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
                            <label for="ix-tax-name" class="text-sm font-medium">{{ __('VAT') }}</label>
                            <x-filament::input.wrapper>
                                <x-filament::input.select id="ix-tax-name" wire:model="taxName" required>
                                    <option value="">{{ __('Select VAT') }}</option>
                                    @foreach ($catalogChoices['taxes'] as $name)
                                        <option value="{{ $name }}">{{ $name }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                            @error('taxName') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                        </div>
                        <div class="space-y-1">
                            <label for="ix-sequence-id" class="text-sm font-medium">{{ __('Document series') }}</label>
                            <x-filament::input.wrapper>
                                <x-filament::input.select id="ix-sequence-id" wire:model="sequenceId" required>
                                    <option value="">{{ __('Select series') }}</option>
                                    @foreach ($catalogChoices['sequences'] as $id => $name)
                                        <option value="{{ $id }}">{{ $name }}</option>
                                    @endforeach
                                    @foreach ($catalogChoices['draftSequences'] as $id => $name)
                                        <option value="{{ $id }}">{{ __(':series · Drafts only', ['series' => $name]) }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                            @if ($catalogChoices['sequences'] === [] && $catalogChoices['draftSequences'] === [])
                                <p class="text-sm">{{ __('Register an invoice series in InvoiceXpress so you can select it here.') }}</p>
                            @endif
                            @error('sequenceId') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    @if ($hasPendingAttempts)
                        <p class="text-sm">{{ __('Resume pending attempts before changing the issuing settings.') }}</p>
                    @endif
                    @error('settings') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                    <x-filament::button type="submit" :disabled="$hasPendingAttempts || ($catalogChoices['sequences'] === [] && $catalogChoices['draftSequences'] === [])" wire:loading.attr="disabled">
                        {{ __('Save settings') }}
                    </x-filament::button>
                </form>
                @endif
            @else
                <p class="text-sm">{{ __('Set up the InvoiceXpress connection to select items, VAT and the series.') }}</p>
            @endif
        </x-filament::section>

    @endif
</x-filament-panels::page>
