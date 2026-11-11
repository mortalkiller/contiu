{{-- A card with a ranked list of bars (MortalKiller\FilamentAppearance\Support\BarList): "Quem deve" beside "A receber", "Onde gastas mais" beside "Fornecedores". --}}
<x-filament-widgets::widget>
    <x-filament::section :heading="$heading">
        @if ($list->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $emptyLabel }}</p>
        @else
            {{ $list }}
        @endif

        @if (filled($buttonUrl ?? null))
            <x-filament::button :href="$buttonUrl" tag="a" color="gray" class="mt-4 w-full">
                {{ $buttonLabel }}
            </x-filament::button>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
