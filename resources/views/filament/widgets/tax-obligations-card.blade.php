@php
    use ToneGabes\Filament\Icons\Enums\Phosphor;

    $readOnlyTooltip = $readOnly ? 'A conta está só de leitura' : null;
@endphp

<x-filament-widgets::widget>
    <div class="grid overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 md:grid-cols-[18rem_1fr] dark:bg-gray-900 dark:ring-white/10">
        {{-- Summary: what the month costs and the next deadline. --}}
        <div class="flex flex-col gap-5 bg-primary-600 p-6 text-white dark:bg-primary-900">
            <div>
                <p class="text-xs font-medium uppercase tracking-wider text-white/70">A pagar em {{ $monthName }}</p>
                <p class="mt-1 text-4xl font-bold tabular-nums leading-none">{{ $pendingTotal }}</p>
                <p class="mt-2 text-sm text-white/80">
                    {{ $pendingCount === 0 ? 'Tudo em dia' : ($pendingCount === 1 ? '1 por fazer' : $pendingCount.' por fazer') }}
                </p>
            </div>

            @if ($next)
                <div class="rounded-lg bg-white/10 p-3">
                    <p class="text-xs text-white/70">Próximo prazo</p>
                    <p class="mt-0.5 font-semibold">{{ $next['date'] }}</p>
                    @if ($next['badge'])
                        <p class="text-sm text-white/85">{{ $next['badge']['label'] }} · {{ $next['title'] }}</p>
                    @else
                        <p class="text-sm text-white/85">{{ $next['title'] }}</p>
                    @endif
                </div>
            @endif

            @if ($socialSecurity)
                <p class="mt-auto flex items-start gap-2 text-sm text-white/85">
                    <x-filament::icon :icon="Phosphor::ShieldCheck" class="mt-0.5 size-4 shrink-0" />
                    <span>{{ $socialSecurity }}</span>
                </p>
            @endif
        </div>

        {{-- Tasks, limits and what comes next. --}}
        <div class="space-y-6 p-6 text-sm">
            <section>
                <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Por fazer</h3>

                @if ($nothingToPay && $tasks === [])
                    <p class="mt-3 flex items-center gap-2 font-medium text-success-700 dark:text-success-400">
                        <x-filament::icon :icon="Phosphor::CheckCircle" class="size-5" />
                        Nada a pagar este mês
                    </p>
                @endif

                <div class="mt-1 divide-y divide-gray-950/5 dark:divide-white/10">
                    @foreach ($tasks as $row)
                        @include('filament.widgets.partials.tax-obligation-row', ['row' => $row, 'readOnlyTooltip' => $readOnlyTooltip])
                    @endforeach
                </div>
            </section>

            @if ($limits !== [])
                <section>
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Limites</h3>

                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        @foreach ($limits as $limit)
                            <div class="rounded-lg p-3 ring-1 ring-gray-950/10 dark:ring-white/10">
                                <div class="flex items-baseline justify-between gap-3">
                                    <span class="text-gray-700 dark:text-gray-300">{{ $limit['label'] }}</span>
                                    <span class="font-semibold tabular-nums text-gray-950 dark:text-white">{{ $limit['percent'] }}</span>
                                </div>
                                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                                    <div @class([
                                        'h-full rounded-full',
                                        'bg-primary-600 dark:bg-primary-500' => $limit['color'] === 'primary',
                                        'bg-warning-500 dark:bg-warning-400' => $limit['color'] === 'warning',
                                        'bg-danger-600 dark:bg-danger-500' => $limit['color'] === 'danger',
                                    ]) style="width: {{ $limit['width'] }}%"></div>
                                </div>
                                <p class="mt-1.5 text-xs text-gray-500 tabular-nums dark:text-gray-400">{{ $limit['detail'] }}</p>
                                @if ($limit['message'])
                                    <p @class([
                                        'mt-2 text-xs font-medium',
                                        'text-warning-700 dark:text-warning-400' => $limit['color'] === 'warning',
                                        'text-danger-700 dark:text-danger-400' => $limit['color'] === 'danger',
                                    ])>{{ $limit['message'] }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            @if ($upcoming !== [])
                <section>
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">A seguir</h3>

                    <ul class="mt-2 space-y-1.5">
                        @foreach ($upcoming as $item)
                            <li class="flex items-baseline gap-3">
                                <span class="size-2 shrink-0 rounded-full bg-gray-300 dark:bg-white/20"></span>
                                <span class="font-medium text-gray-950 dark:text-white">{{ $item['title'] }}</span>
                                @if ($item['when'])
                                    <span class="text-gray-500 dark:text-gray-400">{{ $item['when'] }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($done !== [])
                <section>
                    <x-filament::link tag="button" size="sm" color="gray" wire:click="toggleDone" :icon="$showDone ? Phosphor::CaretUp : Phosphor::CaretDown">
                        {{ $showDone ? 'Esconder feitas' : 'Ver feitas ('.count($done).')' }}
                    </x-filament::link>

                    @if ($showDone)
                        <div class="mt-1 divide-y divide-gray-950/5 dark:divide-white/10">
                            @foreach ($done as $row)
                                @include('filament.widgets.partials.tax-obligation-row', ['row' => $row, 'readOnlyTooltip' => $readOnlyTooltip, 'isDone' => true])
                            @endforeach
                        </div>
                    @endif
                </section>
            @endif
        </div>
    </div>
</x-filament-widgets::widget>
