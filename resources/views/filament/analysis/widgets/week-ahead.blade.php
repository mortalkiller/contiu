@php
    use ToneGabes\Filament\Icons\Enums\Phosphor;
@endphp

<x-filament-widgets::widget>
    <x-filament::section heading="Esta semana" description="{{ $range }}" :icon="Phosphor::CalendarDots" compact>
        <dl class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1.5 text-sm">
            <dt class="text-gray-600 dark:text-gray-300">
                Entra esta semana
                <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $received }} já recebidos</span>
            </dt>
            <dd class="text-end font-medium tabular-nums text-gray-950 dark:text-white">{{ $incoming }}</dd>

            <dt class="text-gray-600 dark:text-gray-300">
                Sai esta semana
                <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $unpaid }} por pagar</span>
            </dt>
            <dd class="text-end font-medium tabular-nums text-gray-950 dark:text-white">{{ $outgoing }}</dd>

            <dt class="text-gray-600 dark:text-gray-300">Prazos fiscais</dt>
            <dd class="text-end font-medium tabular-nums text-gray-950 dark:text-white">{{ $deadlines }}</dd>

            <dt class="text-gray-600 dark:text-gray-300">Em atraso de semanas anteriores</dt>
            <dd @class([
                'text-end font-semibold tabular-nums',
                'text-danger-700 dark:text-danger-400' => $late > 0,
                'text-gray-950 dark:text-white' => $late === 0,
            ])>{{ $late }}</dd>
        </dl>
    </x-filament::section>
</x-filament-widgets::widget>
