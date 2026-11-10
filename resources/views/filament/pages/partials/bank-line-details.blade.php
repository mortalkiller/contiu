@php
    /** @var array{line: \App\Models\BankTransaction, kind: string, rows: list<array{type: string, reference: string, party: ?string, date: string, covered: string, total: ?string, situation: string, color: string}>, leftover: ?string, difference: ?string, platformPayouts: \Illuminate\Database\Eloquent\Collection<int, \App\Models\PlatformPayout>} $details */
    $line = $details['line'];
@endphp

<div class="space-y-4 text-sm">
    <dl class="grid grid-cols-2 gap-x-6 gap-y-2 rounded-lg bg-gray-50 p-4 sm:grid-cols-4 dark:bg-white/5">
        <div class="col-span-2">
            <dt class="text-xs text-gray-500 dark:text-gray-400">Linha do extrato</dt>
            <dd class="font-medium text-gray-950 dark:text-white">{{ $line->description }}</dd>
        </div>
        <div>
            <dt class="text-xs text-gray-500 dark:text-gray-400">Data</dt>
            <dd class="text-gray-950 dark:text-white">{{ $line->booked_on->format('d/m/Y') }}</dd>
        </div>
        <div>
            <dt class="text-xs text-gray-500 dark:text-gray-400">Montante</dt>
            <dd class="font-medium tabular-nums {{ $line->is_credit ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">{{ \App\Support\Money::format($line->amount) }}</dd>
        </div>
        <div class="col-span-2 sm:col-span-4">
            <dt class="text-xs text-gray-500 dark:text-gray-400">Tratada como</dt>
            <dd class="text-gray-950 dark:text-white">{{ $details['kind'] }}</dd>
        </div>
    </dl>

    @if ($details['platformPayouts']->isNotEmpty())
        <section class="space-y-3">
            <h3 class="font-medium text-gray-950 dark:text-white">Histórico de depósitos de plataforma</h3>
            @foreach ($details['platformPayouts'] as $payout)
                <div class="space-y-2 rounded-lg p-3 ring-1 ring-gray-950/5 dark:ring-white/10">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="font-medium">{{ $payout->platform_name }} · {{ $payout->title }}{{ $payout->reference ? ' · '.$payout->reference : '' }}</p>
                        @if ($payout->undone_at)
                            <x-filament::badge color="gray">Reconciliação desfeita em {{ $payout->undone_at->format('d/m/Y H:i') }}</x-filament::badge>
                        @elseif ($payout->one_off_income_id === null)
                            <x-filament::badge color="warning">Receita removida</x-filament::badge>
                        @else
                            <x-filament::badge color="success">Reconciliado em {{ $payout->created_at->format('d/m/Y H:i') }}</x-filament::badge>
                        @endif
                    </div>
                    <dl class="grid grid-cols-3 gap-2 tabular-nums">
                        @foreach ([
                            'Bruto' => [$payout->original_gross, $payout->gross_eur],
                            'Comissão' => [$payout->original_commission, $payout->commission_eur],
                            'Líquido' => [$payout->original_gross - $payout->original_commission, $payout->gross_eur - $payout->commission_eur],
                        ] as $label => [$original, $eur])
                            <div>
                                <dt class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                                <dd>{{ \App\Support\Money::format($eur) }}</dd>
                                @if ($payout->currency !== 'EUR')
                                    <dd class="text-xs text-gray-500 dark:text-gray-400">{{ \App\Support\PlatformPayoutAmounts::formatOriginal($original, $payout->currency) }}</dd>
                                @endif
                            </div>
                        @endforeach
                    </dl>
                    @if ($payout->fx_rate !== null)
                        <p>Conversão confirmada · {{ str_replace('.', ',', $payout->fx_rate) }} EUR por {{ $payout->currency }} · {{ $payout->fx_reference }}</p>
                    @endif
                    @if ($payout->original_commission > 0)
                        <p>Documento da comissão · {{ $payout->commission_document_number ?? 'Sem documento associado' }}</p>
                    @endif
                </div>
            @endforeach
        </section>
    @endif

    @if ($details['rows'] === [] && $details['platformPayouts']->isEmpty())
        <p class="text-gray-600 dark:text-gray-300">Não cobre nenhum recebimento ou despesa por reconciliar.</p>
    @elseif ($details['rows'] !== [])
        <div class="overflow-x-auto rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10">
            <table class="w-full text-left">
                <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-white/5 dark:text-gray-400">
                    <tr>
                        <th class="px-3 py-2 font-medium">Registo</th>
                        <th class="px-3 py-2 font-medium">Data</th>
                        <th class="px-3 py-2 text-end font-medium">Valor</th>
                        <th class="px-3 py-2 font-medium">Situação</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-950/5 dark:divide-white/10">
                    @foreach ($details['rows'] as $row)
                        <tr>
                            <td class="px-3 py-2">
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $row['type'] }}</span>
                                <span class="block font-medium text-gray-950 dark:text-white">{{ $row['reference'] }}</span>
                                @if ($row['party'])
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $row['party'] }}</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-gray-700 dark:text-gray-300">{{ $row['date'] }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-end tabular-nums">
                                <span class="text-gray-950 dark:text-white">{{ $row['covered'] }}</span>
                                @if ($row['total'])
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">de {{ $row['total'] }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2"><x-filament::badge :color="$row['color']">{{ $row['situation'] }}</x-filament::badge></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($details['leftover'])
        <p class="text-gray-600 dark:text-gray-300">{{ $details['leftover'] }}</p>
    @endif

    @if ($details['difference'])
        <p class="text-gray-600 dark:text-gray-300">{{ $details['difference'] }}</p>
    @endif
</div>
