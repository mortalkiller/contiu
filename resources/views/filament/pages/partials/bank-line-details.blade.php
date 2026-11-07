@php
    /** @var array{line: \App\Models\BankTransaction, kind: string, rows: list<array{type: string, reference: string, party: ?string, date: string, covered: string, total: ?string, situation: string, color: string}>, leftover: ?string, difference: ?string} $details */
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

    @if ($details['rows'] === [])
        <p class="text-gray-600 dark:text-gray-300">Não cobre nenhum recebimento ou despesa por reconciliar.</p>
    @else
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
