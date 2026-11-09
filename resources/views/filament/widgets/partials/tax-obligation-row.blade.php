@php
    /** @var array{key: string, kind: string, title: string, amount: ?string, detail: string, badge: ?array{label: string, color: string}, mark: ?string, link: ?array{label: string, url: string}, tone: string} $row */
    $isDone ??= false;
    $isNote = in_array($row['kind'], ['incomplete-settings', 'foreign-vat-warning'], true);
@endphp

<div @class(['flex flex-wrap items-center gap-x-4 gap-y-2 py-3', 'opacity-75' => $isDone]) wire:key="tax-obligation-{{ $row['key'] }}{{ $isDone ? '-done' : '' }}">
    <span @class([
        'size-2.5 shrink-0 rounded-full',
        'bg-danger-500' => $row['tone'] === 'danger' && ! $isDone,
        'bg-warning-500' => $row['tone'] === 'warning' && ! $isDone,
        'bg-gray-300 dark:bg-white/20' => $row['tone'] === 'gray' && ! $isDone,
        'bg-success-500' => $isDone,
    ])></span>

    <div class="min-w-0 flex-1 basis-56">
        <p @class(['font-semibold', 'text-gray-950 dark:text-white' => ! $isNote, 'text-warning-700 dark:text-warning-400' => $isNote])>{{ $row['title'] }}</p>
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $row['detail'] }}</p>
        @if ($row['badge'] || $row['link'])
            <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1">
                @if ($row['badge'])
                    <x-filament::badge :color="$row['badge']['color']" size="sm">{{ $row['badge']['label'] }}</x-filament::badge>
                @endif
                @if ($row['link'])
                    <x-filament::link :href="$row['link']['url']" size="sm" color="gray">{{ $row['link']['label'] }}</x-filament::link>
                @endif
            </div>
        @endif
    </div>

    <div class="ms-auto flex items-center gap-3">
        @if ($row['amount'])
            <span class="text-base font-semibold tabular-nums text-gray-950 dark:text-white">{{ $row['amount'] }}</span>
        @endif

        @if ($isDone)
            <x-filament::button
                size="sm"
                color="gray"
                :disabled="filled($readOnlyTooltip)"
                :tooltip="$readOnlyTooltip"
                :wire:click="filled($readOnlyTooltip) ? null : 'reopen('.\Illuminate\Support\Js::from($row['key']).')'"
            >
                Desfazer
            </x-filament::button>
        @elseif ($row['mark'])
            <x-filament::button
                size="sm"
                :disabled="filled($readOnlyTooltip)"
                :tooltip="$readOnlyTooltip"
                :wire:click="filled($readOnlyTooltip) ? null : 'complete('.\Illuminate\Support\Js::from($row['key']).')'"
            >
                {{ $row['mark'] }}
            </x-filament::button>
        @endif
    </div>
</div>
