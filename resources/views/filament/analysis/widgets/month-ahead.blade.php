@php
    use ToneGabes\Filament\Icons\Enums\Phosphor;
@endphp

<x-filament-widgets::widget>
    <x-filament::section heading="O resto de {{ $month }}" :icon="Phosphor::ArrowsDownUp" compact>
        <dl class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1.5 text-sm">
            <dt class="text-gray-600 dark:text-gray-300">Ainda vai entrar</dt>
            <dd class="text-end font-medium tabular-nums text-gray-950 dark:text-white">{{ $incoming }}</dd>

            <dt class="text-gray-600 dark:text-gray-300">Ainda vai sair</dt>
            <dd class="text-end font-medium tabular-nums text-gray-950 dark:text-white">{{ $outgoing }}</dd>

            <dt class="font-semibold text-gray-950 dark:text-white">Diferença prevista</dt>
            <dd @class([
                'text-end font-semibold tabular-nums',
                'text-danger-700 dark:text-danger-400' => $negative,
                'text-gray-950 dark:text-white' => ! $negative,
            ])>{{ $difference }}</dd>
        </dl>

        <div class="mt-3">{{ $bar }}</div>

        <a href="{{ $forecastUrl }}" class="mt-3 inline-block text-sm font-medium text-primary-700 hover:underline dark:text-primary-400">
            Ver previsão de tesouraria
        </a>
    </x-filament::section>
</x-filament-widgets::widget>
