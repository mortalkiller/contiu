@php
    /** @var array{key: string, title: string, tooltip: string, url: ?string, when: ?string, overdue: bool, amount: ?string, mark: ?string, markTooltip: ?string, asksExpense: bool, tone: string, note: bool, link: ?string, receivable: ?int} $row */
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

    {{--
        The deadline, the amount and the button are columns of a fixed width from a tablet up, so
        they line up from row to row even when a row has no amount or a wider button. On a phone
        there is no room for that: each takes only what it needs.
    --}}
    <span @class(['shrink-0 whitespace-nowrap text-right text-xs tabular-nums sm:w-28', 'hidden sm:block' => blank($row['when']), 'text-danger-600 dark:text-danger-400' => $row['overdue'], 'text-gray-500 dark:text-gray-400' => ! $row['overdue']])>{{ $row['when'] }}</span>

    <span @class(['shrink-0 whitespace-nowrap text-right font-semibold tabular-nums text-gray-950 sm:w-24 dark:text-white', 'hidden sm:block' => blank($row['amount'])])>{{ $row['amount'] }}</span>

    <span class="flex shrink-0 justify-end sm:w-24">
    @if ($isDone)
        <x-filament::button
            size="xs"
            color="gray"
            :disabled="filled($readOnlyTooltip)"
            :tooltip="$readOnlyTooltip ?? __('Mark as pending again')"
            :wire:click="filled($readOnlyTooltip) ? null : 'reopen('.\Illuminate\Support\Js::from($row['key']).')'"
        >
            {{ __('Undo') }}
        </x-filament::button>
    @elseif ($row['mark'] && $row['asksExpense'] && blank($readOnlyTooltip))
        {{-- A payment: ask whether to record it as an expense, so the bank statement matches. --}}
        <x-filament::modal width="md" :id="'tax-payment-'.$row['key']">
            <x-slot name="trigger">
                <x-filament::button size="xs" color="gray" :tooltip="$row['markTooltip']">{{ $row['mark'] }}</x-filament::button>
            </x-slot>

            <x-slot name="heading">{{ __('Mark as paid') }}</x-slot>

            <x-slot name="description">
                {{ $row['title'] }} · {{ $row['amount'] }}
            </x-slot>

            <p class="text-sm text-gray-600 dark:text-gray-400">
                {{ __('Create the expense for :amount? It will be recorded as paid today so the bank statement and business account match.', ['amount' => $row['amount']]) }}
            </p>

            <x-slot name="footerActions">
                <x-filament::button :wire:click="'complete('.\Illuminate\Support\Js::from($row['key']).', true)'" x-on:click="close">
                    {{ __('Yes, create the expense') }}
                </x-filament::button>
                <x-filament::button color="gray" :wire:click="'complete('.\Illuminate\Support\Js::from($row['key']).', false)'" x-on:click="close">
                    {{ __('Mark as paid only') }}
                </x-filament::button>
            </x-slot>
        </x-filament::modal>
    @elseif ($row['receivable'] ?? null)
        {{ ($this->receiveAction)(['receivable' => $row['receivable']]) }}
    @elseif ($row['link'] ?? null)
        <x-filament::button size="xs" color="gray" tag="a" :href="$row['url']">{{ $row['link'] }}</x-filament::button>
    @elseif ($row['mark'])
        <x-filament::button
            size="xs"
            color="gray"
            :disabled="filled($readOnlyTooltip)"
            :tooltip="$readOnlyTooltip ?? $row['markTooltip']"
            :wire:click="filled($readOnlyTooltip) ? null : 'complete('.\Illuminate\Support\Js::from($row['key']).')'"
        >
            {{ $row['mark'] }}
        </x-filament::button>
    @endif
    </span>
</div>
