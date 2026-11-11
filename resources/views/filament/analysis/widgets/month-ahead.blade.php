<x-filament-widgets::widget>
    <x-filament::section :heading="__(':month in numbers', ['month' => \Illuminate\Support\Str::ucfirst($month)])" compact>
        <dl class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1.5 text-sm">
            <dt class="text-gray-600 dark:text-gray-300">{{ __('Still to come in') }}</dt>
            <dd class="text-end font-medium tabular-nums text-gray-950 dark:text-white">{{ $incoming }}</dd>

            <dt class="text-gray-600 dark:text-gray-300">{{ __('Still to go out') }}</dt>
            <dd class="text-end font-medium tabular-nums text-gray-950 dark:text-white">{{ $outgoing }}</dd>

            <dt class="font-semibold text-gray-950 dark:text-white">{{ __('Projected difference') }}</dt>
            <dd @class([
                'text-end font-semibold tabular-nums',
                'text-danger-700 dark:text-danger-400' => $negative,
                'text-gray-950 dark:text-white' => ! $negative,
            ])>{{ $difference }}</dd>
        </dl>

        <div class="mt-3">{{ $bar }}</div>

        <a href="{{ $forecastUrl }}" class="mt-3 inline-block text-sm font-medium text-primary-700 hover:underline dark:text-primary-400">
            {{ __('View cash flow forecast') }}
        </a>
    </x-filament::section>
</x-filament-widgets::widget>
