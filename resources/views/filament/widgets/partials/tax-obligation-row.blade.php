@php
    /** @var array{key: string, title: string, tooltip: string, url: ?string, when: ?string, overdue: bool, amount: ?string, mark: ?string, markTooltip: ?string, tone: string, note: bool} $row */
    $isDone ??= false;
@endphp

<div @class(['flex items-center gap-3 border-b border-gray-950/5 py-3 dark:border-white/10', 'opacity-70' => $isDone]) wire:key="tax-obligation-{{ $row['key'] }}{{ $isDone ? '-done' : '' }}">
    <span @class([
        'size-2 shrink-0 rounded-full',
        'bg-danger-500' => $row['tone'] === 'danger',
        'bg-warning-500' => $row['tone'] === 'warning',
        'bg-success-500' => $row['tone'] === 'success',
        'bg-gray-300 dark:bg-white/20' => $row['tone'] === 'gray',
    ])></span>

    <span class="min-w-0 flex-1 truncate" x-tooltip="{ content: @js($row['tooltip']), theme: $store.theme }">
        @if ($row['url'])
            <a href="{{ $row['url'] }}" @class(['font-semibold hover:underline', 'text-gray-950 dark:text-white' => ! $row['note'], 'text-warning-700 dark:text-warning-400' => $row['note']])>{{ $row['title'] }}</a>
        @else
            <span @class(['font-semibold', 'text-gray-950 dark:text-white' => ! $row['note'], 'text-warning-700 dark:text-warning-400' => $row['note']])>{{ $row['title'] }}</span>
        @endif
    </span>

    @if ($row['when'])
        <span @class(['shrink-0 whitespace-nowrap text-xs', 'text-danger-600 dark:text-danger-400' => $row['overdue'], 'text-gray-500 dark:text-gray-400' => ! $row['overdue']])>{{ $row['when'] }}</span>
    @endif

    @if ($row['amount'])
        <span class="w-20 shrink-0 text-right font-semibold tabular-nums text-gray-950 dark:text-white">{{ $row['amount'] }}</span>
    @endif

    @if ($isDone)
        <x-filament::button
            size="xs"
            color="gray"
            :disabled="filled($readOnlyTooltip)"
            :tooltip="$readOnlyTooltip ?? 'Voltar a pôr por fazer'"
            :wire:click="filled($readOnlyTooltip) ? null : 'reopen('.\Illuminate\Support\Js::from($row['key']).')'"
        >
            Desfazer
        </x-filament::button>
    @elseif ($row['mark'])
        <x-filament::button
            size="xs"
            :disabled="filled($readOnlyTooltip)"
            :tooltip="$readOnlyTooltip ?? $row['markTooltip']"
            :wire:click="filled($readOnlyTooltip) ? null : 'complete('.\Illuminate\Support\Js::from($row['key']).')'"
        >
            {{ $row['mark'] }}
        </x-filament::button>
    @endif
</div>
