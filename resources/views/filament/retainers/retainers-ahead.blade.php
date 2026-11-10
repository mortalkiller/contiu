@php
    use ToneGabes\Filament\Icons\Enums\Phosphor;
@endphp

<x-filament-widgets::widget>
    <x-filament::section heading="Próximos 6 meses" description="O que as avenças ativas vão gerar" :icon="Phosphor::CalendarDots" compact>
        <dl class="grid grid-cols-3 gap-x-4 gap-y-3 md:grid-cols-6">
            @foreach ($months as $month)
                <div>
                    <dt class="text-xs text-gray-500 dark:text-gray-400">{{ $month['label'] }}</dt>
                    <dd class="text-sm font-semibold tabular-nums text-gray-950 dark:text-white">{{ $month['amount'] }}</dd>
                </div>
            @endforeach
        </dl>
    </x-filament::section>
</x-filament-widgets::widget>
