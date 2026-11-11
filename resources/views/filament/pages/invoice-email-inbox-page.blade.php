<x-filament-panels::page>
    <div>
        <x-filament::badge color="gray">{{ __('Simulated') }}</x-filament::badge>
    </div>

    @if ($canSimulate)
        <x-filament::section :heading="__('Email simulator')">
            <div class="space-y-4">
                <div class="flex flex-wrap items-center gap-3">
                    <x-filament::button type="button" color="gray" wire:click="loadSample" wire:loading.attr="disabled">
                        {{ __('Load sample invoice') }}
                    </x-filament::button>
                    @if ($recipient)
                        <span class="break-all text-sm">{{ $recipient }}</span>
                        <x-filament::button type="button" color="gray" size="sm" wire:click="rotateAlias"
                            wire:confirm="{{ __('Replace the current simulated address?') }}">
                            {{ __('Rotate simulated address') }}
                        </x-filament::button>
                    @endif
                </div>

                @if ($canUpload)
                <form wire:submit="simulate" class="space-y-3">
                    <div class="space-y-1">
                        <label for="invoice-email-pdf" class="block text-sm font-medium">{{ __('Invoice PDF') }}</label>
                        <input id="invoice-email-pdf" type="file" wire:model="pdf" accept="application/pdf,.pdf">
                        @error('pdf') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                    </div>

                    <div class="space-y-1">
                        <label for="invoice-email-qr" class="block text-sm font-medium">{{ __('QR code contents (optional)') }}</label>
                        <x-filament::input.wrapper :valid="! $errors->has('qrText')">
                            <textarea id="invoice-email-qr" class="w-full px-3 py-2 text-sm" wire:model="qrText"
                                maxlength="{{ \App\Support\ExpenseDocumentQr::MAX_LENGTH }}" rows="2"></textarea>
                        </x-filament::input.wrapper>
                        @error('qrText') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                    </div>

                    @if ($canScanPdf)
                        <label class="flex items-center gap-2 text-sm">
                            <x-filament::input.checkbox wire:model="scanPdf" />
                            {{ __('Try reading the QR code on the first page') }}
                        </label>
                    @endif

                    <x-filament::button type="submit" wire:loading.attr="disabled">
                        {{ __('Simulate receipt') }}
                    </x-filament::button>
                </form>
                @endif
            </div>
        </x-filament::section>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
