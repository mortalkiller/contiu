<x-filament-panels::page>
    @include('filament.pages.partials.tax-summary', ['stats' => $summary, 'notice' => ['tone' => 'warning', ...$summaryNotice]])

    <form wire:submit="compare" class="space-y-4">
        {{ $this->form }}

        <x-filament::button type="submit">Comparar</x-filament::button>
    </form>

    <div aria-live="polite">
        @if ($result !== null)
            <div wire:dirty.remove>
                <x-filament::section :heading="'Comparação para '.$result['year']">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-gray-600 dark:text-gray-400">
                                    <th scope="col" class="p-2 text-left">Rubrica</th>
                                    <th scope="col" class="p-2 text-end">Base documentada</th>
                                    <th scope="col" class="p-2 text-end">Nova avença</th>
                                    <th scope="col" class="p-2 text-end">Base + avença</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 tabular-nums dark:divide-gray-700">
                                @foreach ([
                                    ['Faturação sem IVA', 'baselineBilling', 'retainerBilling', 'scenarioBilling'],
                                    ['Retenção na fonte', 'baselineRetention', 'scenarioRetention', 'combinedRetention'],
                                ] as [$label, $baseline, $retainer, $total])
                                    <tr>
                                        <th scope="row" class="p-2 text-left font-medium">{{ $label }}</th>
                                        <td class="p-2 text-end">{{ $result[$baseline] === null ? 'Sem dados documentados' : \App\Support\Money::format($result[$baseline]) }}</td>
                                        <td class="p-2 text-end">{{ $result[$retainer] === null ? 'Por indicar' : \App\Support\Money::format($result[$retainer]) }}</td>
                                        <td class="p-2 text-end font-semibold">{{ $result[$total] === null ? 'Dados em falta' : \App\Support\Money::format($result[$total]) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-4 space-y-2 text-sm text-gray-600 dark:text-gray-400">
                        <p>Avença adicional de {{ \App\Support\Money::format($result['monthlyNet']) }} por mês × {{ $result['months'] }} {{ $result['months'] === 1 ? 'mês' : 'meses' }} no ano.</p>
                        <p>A faturação documentada usa faturas e faturas-recibo emitidas e ativas em EUR, deduzidas das notas de crédito, pela data do documento.</p>
                        <p>A retenção documentada soma os registos confirmados que continuam válidos, pelo ano do recebimento. A retenção da avença vem do valor que indicaste.</p>
                        @if ($result['foreignDocuments'] > 0)
                            <p>{{ $result['foreignDocuments'] }} {{ $result['foreignDocuments'] === 1 ? 'documento noutra moeda ficou fora da base' : 'documentos noutras moedas ficaram fora da base' }}.</p>
                        @endif
                        @if ($result['staleRetentionRecords'] > 0)
                            <p>{{ $result['staleRetentionRecords'] }} {{ $result['staleRetentionRecords'] === 1 ? 'registo de retenção a reconfirmar ficou fora da soma' : 'registos de retenção a reconfirmar ficaram fora da soma' }}.</p>
                        @endif
                    </div>
                </x-filament::section>
                @if ($result['settlement'] !== null)
                    <x-filament::section heading="Estimativa de IRS de 2026">
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr>
                                        <th scope="col" class="p-2 text-start">Rubrica</th>
                                        <th scope="col" class="p-2 text-end">Cenário atual</th>
                                        <th scope="col" class="p-2 text-end">Com a avença</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 tabular-nums dark:divide-gray-700">
                                    @foreach (['grossTax' => 'Coleta pelas taxas gerais', 'solidarity' => 'Adicional de solidariedade', 'taxAfterDeductions' => 'Imposto após deduções', 'payable' => 'IRS estimado a pagar', 'refund' => 'Reembolso estimado'] as $key => $label)
                                        <tr>
                                            <th scope="row" class="p-2 text-start font-medium">{{ $label }}</th>
                                            <td class="p-2 text-end">{{ \App\Support\Money::format($result['settlement']['baseline'][$key]) }}</td>
                                            <td class="p-2 text-end">{{ \App\Support\Money::format($result['settlement']['combined'][$key]) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">
                            Usa os valores que introduziste para cada cenário e aplica os limites mínimos de cobrança e reembolso.
                        </p>
                    </x-filament::section>
                @endif
            </div>
        @endif
    </div>
</x-filament-panels::page>
