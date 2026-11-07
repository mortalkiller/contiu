@php
    /** @var array{done: list<array{date: string, description: string, amount: string, target: string}>, left: list<array{date: string, description: string, amount: string}>} $plan */
    $done = $plan['done'];
    $left = $plan['left'];
@endphp

<div class="space-y-4 text-sm">
    @if ($done === [])
        <p class="text-gray-600 dark:text-gray-300">Nenhuma linha tem uma sugestão segura. Trata-as à mão com <strong>Associar…</strong>, <strong>Criar despesa</strong> ou <strong>Criar receita</strong>.</p>
    @else
        <p class="text-gray-600 dark:text-gray-300">Vai tratar {{ count($done) === 1 ? '1 linha' : count($done).' linhas' }}:</p>

        <div class="max-h-80 overflow-y-auto rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10">
            <table class="w-full text-left">
                <thead class="sticky top-0 bg-gray-50 text-xs text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                    <tr>
                        <th class="px-3 py-2 font-medium">Linha do extrato</th>
                        <th class="px-3 py-2 text-end font-medium">Montante</th>
                        <th class="px-3 py-2 font-medium">Fica como</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-950/5 dark:divide-white/10">
                    @foreach ($done as $row)
                        <tr>
                            <td class="px-3 py-2">
                                <span class="block text-gray-950 dark:text-white">{{ $row['description'] }}</span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $row['date'] }}</span>
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-end tabular-nums">{{ $row['amount'] }}</td>
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ $row['target'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($left !== [])
        <div>
            <p class="font-medium text-gray-950 dark:text-white">Fica por tratar ({{ count($left) }})</p>
            <ul class="mt-1 space-y-0.5 text-gray-600 dark:text-gray-400">
                @foreach ($left as $row)
                    <li>{{ $row['date'] }} · {{ $row['description'] }} · <span class="tabular-nums">{{ $row['amount'] }}</span></li>
                @endforeach
            </ul>
        </div>
    @endif

    <p class="text-xs text-gray-500 dark:text-gray-400">Cada linha pode ser desfeita depois com "Voltar a tratar".</p>
</div>
