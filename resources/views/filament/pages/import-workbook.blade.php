<x-filament-panels::page>
    {{ $this->form }}

    @if ($preview)
        <x-filament::section
            :heading="$preview['dryRun'] ? 'Pré-visualização' : 'Resultado'"
            description="Nada foi gravado ainda. Revê os números e os avisos e carrega em Importar."
            icon="phosphor-eye"
        >
            <div class="grid gap-6 md:grid-cols-2">
                <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
                    @foreach ($this->previewRows() as $row)
                        <dt class="text-gray-500 dark:text-gray-400">{{ $row['label'] }}</dt>
                        <dd class="text-end font-medium tabular-nums text-gray-950 dark:text-white">{{ $row['value'] }}</dd>
                    @endforeach
                </dl>

                <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
                    <dt class="text-gray-500 dark:text-gray-400">Contratado</dt>
                    <dd class="text-end font-medium tabular-nums text-gray-950 dark:text-white">{{ \App\Support\Money::format($preview['contracted']) }}</dd>
                    <dt class="text-gray-500 dark:text-gray-400">Recebido</dt>
                    <dd class="text-end font-medium tabular-nums text-gray-950 dark:text-white">{{ \App\Support\Money::format($preview['received']) }}</dd>
                    <dt class="text-gray-500 dark:text-gray-400">Por receber</dt>
                    <dd class="text-end font-medium tabular-nums text-gray-950 dark:text-white">{{ \App\Support\Money::format($preview['contracted'] - $preview['received']) }}</dd>
                    <dt class="text-gray-500 dark:text-gray-400">Despesas</dt>
                    <dd class="text-end font-medium tabular-nums text-gray-950 dark:text-white">{{ \App\Support\Money::format($preview['expenses']) }}</dd>
                </dl>
            </div>

            @if (filled($preview['warnings']))
                <div class="mt-6 rounded-lg bg-warning-50 p-4 text-sm text-warning-800 ring-1 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-300 dark:ring-warning-400/30">
                    <p class="font-medium">{{ count($preview['warnings']) }} {{ count($preview['warnings']) === 1 ? 'aviso' : 'avisos' }}</p>
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
