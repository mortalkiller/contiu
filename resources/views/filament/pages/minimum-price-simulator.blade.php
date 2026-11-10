<x-filament-panels::page>
    <form wire:submit="calculate" class="space-y-4">
        {{ $this->form }}

        <x-filament::button type="submit">
            Calcular
        </x-filament::button>
    </form>

    <div aria-live="polite">
        @if ($result !== null)
            <div wire:dirty.remove>
                <x-filament::section heading="Faturação mínima">
                    <dl class="grid gap-4 sm:grid-cols-3">
                        @foreach (['annual' => 'Por ano', 'monthly' => 'Por mês faturável', 'hourly' => 'Por hora faturável'] as $period => $label)
                            <div>
                                <dt class="text-sm text-gray-600 dark:text-gray-400">{{ $label }}</dt>
                                <dd class="mt-1 text-xl font-semibold tabular-nums">{{ $this->formatMinimum($result[$period]) }}</dd>
                                @if ($inputs !== null)
                                    <dd class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                                        {{ match ($period) {
                                            'annual' => 'Faturação necessária',
                                            'monthly' => trans_choice('{1} Em 1 mês|[2,*] Em :count meses', $inputs['billableMonths']),
                                            'hourly' => 'Em '.rtrim(rtrim(number_format($inputs['hoursPerMonth'] / 100, 2, ',', ' '), '0'), ',').' horas por mês',
                                        } }}
                                    </dd>
                                @endif
                            </div>
                        @endforeach
                    </dl>

                    <div class="mt-4 space-y-1 text-sm text-gray-600 dark:text-gray-400">
                        <p>Anual = (meta de rendimento anual + custos anuais) ÷ (1 − reserva % ÷ 100).</p>
                        <p>Mensal = anual ÷ meses faturáveis. Por hora = mensal ÷ horas por mês faturável.</p>
                        <p>Cada valor é arredondado para cima ao cêntimo, a partir do valor exato.</p>
                    </div>
                </x-filament::section>

                @if (($bar = $this->breakdownBar()) !== null)
                    <x-filament::section heading="Como se chega aqui" class="mt-6">
                        {{ $bar }}

                        <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">
                            ({{ $this->formatMinimum($inputs['incomeGoal']) }} + {{ $this->formatMinimum($inputs['costs']) }})
                            ÷ (1 − {{ rtrim(rtrim(number_format($inputs['reservePercent'] / 100, 2, ',', ' '), '0'), ',') }}%)
                            = {{ $this->formatMinimum($result['annual']) }} por ano.
                        </p>
                    </x-filament::section>
                @endif

                @if (($comparison = $this->comparison()) !== null)
                    <x-filament::section heading="Comparado com o que cobras hoje" class="mt-6">
                        <dl class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1.5 text-sm">
                            <dt class="text-gray-600 dark:text-gray-300">Faturado em {{ $comparison['year'] }}, até agora</dt>
                            <dd class="text-end font-medium tabular-nums text-gray-950 dark:text-white">{{ $comparison['billed'] }}</dd>

                            @if ($comparison['pace'] !== null)
                                <dt class="text-gray-600 dark:text-gray-300">Ritmo necessário até dezembro</dt>
                                <dd class="text-end font-medium tabular-nums text-gray-950 dark:text-white">{{ $comparison['pace'] }} por mês</dd>
                            @endif
                        </dl>

                        <span class="contiu-progress mt-3" role="img" aria-label="{{ $comparison['percent'] }}% da meta anual">
                            <i style="width: {{ $comparison['percent'] }}%"></i>
                        </span>

                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                            @if ($comparison['reached'])
                                Já faturaste o mínimo anual desta simulação.
                            @else
                                Vais em {{ $comparison['percent'] }}% do mínimo anual desta simulação, pelos documentos fiscais emitidos.
                            @endif
                        </p>
                    </x-filament::section>
                @endif
            </div>
        @endif
    </div>
</x-filament-panels::page>
