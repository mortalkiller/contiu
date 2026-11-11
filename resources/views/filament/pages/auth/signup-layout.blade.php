{{-- The sign-up's own layout (RegisterAccount): the page fills the window, without the simple layout's centred card. --}}
<x-filament-panels::layout.base :livewire="$livewire ?? null">
    {{ $slot }}
</x-filament-panels::layout.base>
