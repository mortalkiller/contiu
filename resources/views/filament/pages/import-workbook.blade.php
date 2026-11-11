<x-filament-panels::page>
    {{ $this->form }}

    @if ($preview)
        <x-filament::section
            :heading="$preview['dryRun'] ? __('Preview results') : __('Result')"
            :description="__('Nothing has been saved yet. Review the figures and warnings, then click Import.')"
            icon="phosphor-eye"
            wire:dirty.remove
            wire:target="data.replace,data.file"
        >
            <div class="grid gap-6 md:grid-cols-2">
                <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
                    @foreach ($this->previewRows() as $row)
                        <dt class="text-gray-500 dark:text-gray-400">{{ $row['label'] }}</dt>
                        <dd class="text-end font-medium tabular-nums text-gray-950 dark:text-white">{{ $row['value'] }}</dd>
                    @endforeach
                </dl>

                <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('Contracted') }}</dt>
                    <dd class="text-end font-medium tabular-nums text-gray-950 dark:text-white">{{ \App\Support\Money::format($preview['contracted'], app()->getLocale()) }}</dd>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('Received') }}</dt>
                    <dd class="text-end font-medium tabular-nums text-gray-950 dark:text-white">{{ \App\Support\Money::format($preview['received'], app()->getLocale()) }}</dd>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('To be collected') }}</dt>
                    <dd class="text-end font-medium tabular-nums text-gray-950 dark:text-white">{{ \App\Support\Money::format($preview['contracted'] - $preview['received'], app()->getLocale()) }}</dd>
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('Expenses') }}</dt>
                    <dd class="text-end font-medium tabular-nums text-gray-950 dark:text-white">{{ \App\Support\Money::format($preview['expenses'], app()->getLocale()) }}</dd>
                </dl>
            </div>

            @if (filled($preview['warnings']))
                <div class="mt-6 rounded-lg bg-warning-50 p-4 text-sm text-warning-800 ring-1 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-300 dark:ring-warning-400/30">
                    <p class="font-medium">{{ trans_choice('{1} 1 warning|[2,*] :count warnings', count($preview['warnings'])) }}</p>
                    <ul class="mt-2 list-disc space-y-1 ps-5">
                        @foreach ($preview['warnings'] as $warning)
                            <li>{{ $warning }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @foreach ($preview['info'] as $info)
                <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ $info }}</p>
            @endforeach
        </x-filament::section>
    @endif
</x-filament-panels::page>
