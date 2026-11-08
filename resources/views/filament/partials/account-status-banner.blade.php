@php
    /** @var array{kind: string, message: string, subscribeUrl: ?string} $banner */
    $readOnly = $banner['kind'] === 'read_only';
@endphp

<div @class([
    'mb-6 flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl px-4 py-3 text-sm',
    'bg-gray-50 text-gray-700 ring-1 ring-gray-950/5 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10' => ! $readOnly,
    'bg-warning-50 text-warning-800 ring-1 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-300 dark:ring-warning-400/20' => $readOnly,
]) role="status" data-account-status="{{ $banner['kind'] }}">
    <x-filament::icon
        :icon="$readOnly ? \ToneGabes\Filament\Icons\Enums\Phosphor::LockSimple : \ToneGabes\Filament\Icons\Enums\Phosphor::Hourglass"
        class="size-5 shrink-0"
    />

    <span class="flex-1">{{ $banner['message'] }}</span>

    @if ($banner['subscribeUrl'])
        <x-filament::button tag="a" :href="$banner['subscribeUrl']" size="sm" :color="$readOnly ? 'warning' : 'primary'">
            Subscrever
        </x-filament::button>
    @endif
</div>
