@php
    use App\Support\Money;
    use ToneGabes\Filament\Icons\Enums\Phosphor;

    $readOnlyTooltip = $readOnly ? 'A conta está só de leitura' : null;
@endphp

<x-filament-widgets::widget>
    <x-filament::section :icon="Phosphor::CalendarCheck" icon-color="primary">
        <x-slot name="heading">Este mês · {{ $month }}</x-slot>

        @if ($pendingCount > 0)
            <x-slot name="afterHeader">
                <span class="text-sm text-gray-500 tabular-nums dark:text-gray-400">
                    {{ $pendingCount }} por fazer{{ $pendingTotal > 0 ? ' · '.Money::format($pendingTotal) : '' }}
                </span>
            </x-slot>
        @endif

        <div class="space-y-2 text-sm">
            @if ($nothingToPay)
                <div class="flex items-center gap-2 rounded-lg bg-success-50 px-3 py-2.5 text-success-700 dark:bg-success-400/10 dark:text-success-400">
                    <x-filament::icon :icon="Phosphor::CheckCircle" class="size-5 shrink-0" />
                    <span class="font-medium">Nada a pagar este mês</span>
                </div>
            @endif

            @foreach ($rows as $row)
                @include('filament.widgets.partials.tax-obligation-row', ['row' => $row, 'readOnlyTooltip' => $readOnlyTooltip])
            @endforeach

            @if ($upcoming !== [])
                <p class="pt-1 text-xs text-gray-500 dark:text-gray-400">
                    <span class="font-semibold text-gray-700 dark:text-gray-300">A seguir:</span>
                    {{ implode(' · ', $upcoming) }}
                </p>
            @endif

            @if ($done !== [])
                <div class="pt-1">
                    <x-filament::link tag="button" size="sm" color="gray" wire:click="toggleDone" :icon="$showDone ? Phosphor::CaretUp : Phosphor::CaretDown">
                        {{ $showDone ? 'Esconder feitas' : 'Ver feitas ('.count($done).')' }}
                    </x-filament::link>

                    @if ($showDone)
                        <div class="mt-2 space-y-2">
                            @foreach ($done as $row)
                                @include('filament.widgets.partials.tax-obligation-row', ['row' => $row, 'readOnlyTooltip' => $readOnlyTooltip, 'isDone' => true])
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
