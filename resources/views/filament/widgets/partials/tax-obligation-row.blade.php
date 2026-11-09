@php
    /** @var array{key: string, kind: string, title: string, amount: ?string, detail: string, badge: ?array{label: string, color: string}, mark: ?string, link: ?array{label: string, url: string}, threshold: ?array{percent: string, width: float, color: string}} $row */
    $isDone ??= false;
    $isNote = in_array($row['kind'], ['incomplete-settings', 'foreign-vat-warning'], true);
@endphp

<div @class([
    'rounded-lg px-3 py-2.5 ring-1',
    'ring-gray-950/10 dark:ring-white/10' => ! $isNote,
    'bg-warning-50 ring-warning-600/20 dark:bg-warning-400/10 dark:ring-warning-400/20' => $isNote,
    'opacity-75' => $isDone,
]) wire:key="tax-obligation-{{ $row['key'] }}{{ $isDone ? '-done' : '' }}">
    <div class="flex items-baseline justify-between gap-3">
        <span class="font-semibold text-gray-950 dark:text-white">{{ $row['title'] }}</span>

        @if ($row['amount'])
            <span class="shrink-0 font-semibold text-gray-950 tabular-nums dark:text-white">{{ $row['amount'] }}</span>
        @elseif ($row['threshold'])
            <span class="shrink-0 text-gray-700 tabular-nums dark:text-gray-300">{{ $row['threshold']['percent'] }}</span>
        @endif
    </div>

    @if ($row['threshold'])
        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
            <div @class([
                'h-full rounded-full',
                'bg-primary-600 dark:bg-primary-500' => $row['threshold']['color'] === 'primary',
                'bg-warning-500 dark:bg-warning-400' => $row['threshold']['color'] === 'warning',
                'bg-danger-600 dark:bg-danger-500' => $row['threshold']['color'] === 'danger',
            ]) style="width: {{ $row['threshold']['width'] }}%"></div>
        </div>
    @endif

    <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">{{ $row['detail'] }}</p>

    @if ($row['badge'] || $row['link'] || $row['mark'] || $isDone)
        <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1">
            @if ($row['badge'])
                <x-filament::badge :color="$row['badge']['color']" size="sm">{{ $row['badge']['label'] }}</x-filament::badge>
            @endif

            @if ($row['link'])
                <x-filament::link :href="$row['link']['url']" size="sm" color="gray">{{ $row['link']['label'] }}</x-filament::link>
            @endif

            @if ($isDone)
                <x-filament::link
                    tag="button"
                    size="sm"
                    color="gray"
                    :disabled="filled($readOnlyTooltip)"
                    :tooltip="$readOnlyTooltip"
                    :wire:click="filled($readOnlyTooltip) ? null : 'reopen('.\Illuminate\Support\Js::from($row['key']).')'"
                >
                    Desfazer
                </x-filament::link>
            @elseif ($row['mark'])
                <x-filament::link
                    tag="button"
                    size="sm"
                    :disabled="filled($readOnlyTooltip)"
                    :tooltip="$readOnlyTooltip"
                    :wire:click="filled($readOnlyTooltip) ? null : 'complete('.\Illuminate\Support\Js::from($row['key']).')'"
                >
                    {{ $row['mark'] }}
                </x-filament::link>
            @endif
        </div>
    @endif
</div>
