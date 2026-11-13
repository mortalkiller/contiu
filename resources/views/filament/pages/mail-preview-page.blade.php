<x-filament-panels::page>
    <x-filament::section>
        <div class="grid gap-4 sm:grid-cols-3">
            <label class="space-y-2 text-sm font-medium">
                <span>{{ __('Email template') }}</span>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="template">
                        @foreach ($templates as $key => $label)
                            <option value="{{ $key }}">{{ __($label) }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
            <label class="space-y-2 text-sm font-medium">
                <span>{{ __('Language') }}</span>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="locale">
                        @foreach ($locales as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
            <label class="space-y-2 text-sm font-medium">
                <span>{{ __('Preview theme') }}</span>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="theme">
                        @foreach ($themes as $key => $label)
                            <option value="{{ $key }}">{{ __($label) }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">{{ __('Subject') }}</x-slot>
        <p class="mb-4 break-words text-sm">{{ $subject }}</p>
        <iframe
            src="{{ $previewUrl }}"
            title="{{ __('Email previews') }}"
            sandbox
            @class([
                'h-[65vh] min-h-96 w-full rounded-lg border border-gray-200 dark:border-white/10',
                'bg-gray-900 [color-scheme:dark]' => $previewTheme === 'dark',
                'bg-white [color-scheme:light]' => $previewTheme === 'light',
            ])
        ></iframe>
    </x-filament::section>
</x-filament-panels::page>
