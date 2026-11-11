@php
    /** @var array{kind: string, message: string, detail?: string, urgent?: bool, subscribeUrl: ?string} $banner */
    // As in the mock: amber when it calls for action (read-only, or the trial's last days), quiet otherwise.
    $warning = $banner['kind'] === 'read_only' || ($banner['urgent'] ?? false);
@endphp

<div @class([
    'mb-6 flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl px-4 py-3 text-sm',
    'bg-gray-50 text-gray-700 ring-1 ring-gray-950/5 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10' => ! $warning,
    'bg-warning-50 text-warning-800 ring-1 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-300 dark:ring-warning-400/20' => $warning,
]) role="status" data-account-status="{{ $banner['kind'] }}">
    <x-filament::icon
        :icon="$banner['kind'] === 'read_only' ? \ToneGabes\Filament\Icons\Enums\Phosphor::LockSimple : \ToneGabes\Filament\Icons\Enums\Phosphor::Hourglass"
        class="size-5 shrink-0"
    />

    <span class="flex-1">
        <strong class="font-semibold">{{ $banner['message'] }}{{ $banner['kind'] === 'trial' ? '.' : '' }}</strong>
        @if (filled($banner['detail'] ?? null)) {{ $banner['detail'] }} @endif
    </span>

    @if ($banner['subscribeUrl'])
        <x-filament::button tag="a" :href="$banner['subscribeUrl']" size="sm" color="gray">
            {{ __('See plans') }}
        </x-filament::button>
    @endif
</div>
