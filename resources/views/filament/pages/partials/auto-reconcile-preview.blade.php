@php
    /** @var array{done: list<array{date: string, description: string, amount: string, target: string, own: bool, covers?: list<string>}>, left: list<array{date: string, description: string, amount: string}>} $plan */
    $done = $plan['done'];
    $left = $plan['left'];
@endphp

<div class="space-y-4 text-sm">
    @if ($done === [])
        <p class="text-gray-600 dark:text-gray-300">{!! __('No lines have a reliable suggestion. Process them manually using :link, :expense or :income.', ['link' => '<strong>'.e(__('Link…')).'</strong>', 'expense' => '<strong>'.e(__('Create expense')).'</strong>', 'income' => '<strong>'.e(__('Create income')).'</strong>']) !!}</p>
    @else
        <p class="text-gray-600 dark:text-gray-300">{{ trans_choice('{1} This will process 1 line:|[2,*] This will process :count lines:', count($done)) }}</p>

        <div class="max-h-80 overflow-y-auto rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10">
            <table class="w-full text-left">
                <thead class="sticky top-0 bg-gray-50 text-xs text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                    <tr>
                        <th class="px-3 py-2 font-medium">{{ __('Bank statement line') }}</th>
                        <th class="px-3 py-2 text-end font-medium">{{ __('Transaction amount') }}</th>
                        <th class="px-3 py-2 font-medium">{{ __('Recorded as') }}</th>
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
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-300">
                                {{ $row['target'] }}
                                @if ($row['own'] ?? false)
                                    @if (($row['covers'] ?? []) === [])
                                        <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">{{ __('It does not cover any unreconciled payments or expenses.') }}</span>
                                    @else
                                        <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">{{ __('Covers:') }}</span>
                                        <ul class="list-disc ps-4 text-xs text-gray-600 dark:text-gray-400">
                                            @foreach ($row['covers'] as $cover)
                                                <li>{{ $cover }}</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($left !== [])
        <div>
            <p class="font-medium text-gray-950 dark:text-white">{{ __('Left to process (:count)', ['count' => count($left)]) }}</p>
            <ul class="mt-1 space-y-0.5 text-gray-600 dark:text-gray-400">
                @foreach ($left as $row)
                    <li>{{ $row['date'] }} · {{ $row['description'] }} · <span class="tabular-nums">{{ $row['amount'] }}</span></li>
                @endforeach
            </ul>
        </div>
    @endif

    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Each line can be undone later using "Reprocess".') }}</p>
</div>
