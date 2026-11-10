<x-filament-panels::page>
    <x-filament::section>
        <div class="flex flex-wrap items-start gap-8">
            <div>
                <label for="territorial-turnover-year" class="text-sm font-medium">Ano</label>
                <x-filament::input.wrapper>
                    <x-filament::input.select id="territorial-turnover-year" wire:model.live="year">
                        @foreach ($years as $year)
                            <option value="{{ $year }}">{{ $year }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>
            <div>
                <p class="text-sm font-medium">{{ $report->subtotalLabel() }}</p>
                <p class="text-xl font-semibold">{{ $report->subtotal() === null ? 'Sem valor confirmado' : \App\Support\Money::format($report->subtotal()) }}</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $report->summary() }}</p>
            </div>
        </div>
        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
            São revistas faturas, faturas-recibo e notas de crédito emitidas e ativas.
            Sem data de operação conhecida, o documento fica por rever até ser atribuído a um ano.
        </p>
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
