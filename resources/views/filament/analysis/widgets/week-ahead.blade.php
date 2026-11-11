@php
    use ToneGabes\Filament\Icons\Enums\Phosphor;
@endphp

<x-filament-widgets::widget>
    <x-filament::section :heading="__('This week')" description="{{ $range }}" compact>
        <dl class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1.5 text-sm">
            <dt class="text-gray-600 dark:text-gray-300">
                {{ __('Coming in this week') }}
                <span class="block text-xs text-gray-500 dark:text-gray-400">{{ __(':amount already received', ['amount' => $received]) }}</span>
            </dt>
            <dd class="text-end font-medium tabular-nums text-gray-950 dark:text-white">{{ $incoming }}</dd>

            <dt class="text-gray-600 dark:text-gray-300">
                {{ __('Going out this week') }}
                <span class="block text-xs text-gray-500 dark:text-gray-400">{{ __(':amount still to pay', ['amount' => $unpaid]) }}</span>
            </dt>
            <dd class="text-end font-medium tabular-nums text-gray-950 dark:text-white">{{ $outgoing }}</dd>

            <dt class="text-gray-600 dark:text-gray-300">{{ __('Tax deadlines') }}</dt>
            <dd class="text-end font-medium tabular-nums text-gray-950 dark:text-white">{{ $deadlines }}</dd>

            <dt class="text-gray-600 dark:text-gray-300">{{ __('Overdue from previous weeks') }}</dt>
            <dd @class([
                'text-end font-semibold tabular-nums',
                'text-danger-700 dark:text-danger-400' => $late > 0,
                'text-gray-950 dark:text-white' => $late === 0,
            ])>{{ $late }}</dd>
        </dl>
    </x-filament::section>
</x-filament-widgets::widget>
