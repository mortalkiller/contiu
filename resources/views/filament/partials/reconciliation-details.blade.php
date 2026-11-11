@php
    /** @var \App\Support\ReconciliationResult $result */
    $isExpense = $isExpense ?? false;
@endphp

<div class="space-y-3 text-sm">
    <div class="flex flex-wrap items-center gap-2">
        <x-filament::badge :color="$result->status->color()">{{ $result->label() }}</x-filament::badge>
        @if ($result->detail())
            <span class="text-gray-600 dark:text-gray-300">{{ $result->detail() }}</span>
        @endif
    </div>

    @if ($result->lines !== [])
        <div class="overflow-x-auto rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10">
            <table class="w-full text-left">
                <thead class="bg-gray-50 text-xs text-gray-500 dark:bg-white/5 dark:text-gray-400">
                    <tr>
                        <th class="px-3 py-2 font-medium">{{ __('Date') }}</th>
                        <th class="px-3 py-2 font-medium">{{ __('Bank statement line') }}</th>
                        <th class="px-3 py-2 text-end font-medium">{{ __('Value') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-950/5 dark:divide-white/10">
                    @foreach ($result->lines as $by)
                        <tr>
                            <td class="whitespace-nowrap px-3 py-2 text-gray-700 dark:text-gray-300">{{ $by['line']->booked_on->format(app()->getLocale() === 'pt_PT' ? 'd/m/Y' : 'm/d/Y') }}</td>
                            <td class="px-3 py-2">
                                <span class="block text-gray-950 dark:text-white">{{ $by['line']->description }}</span>
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ match (true) {
                                    $by['direct'] => __('Linked to this line'),
                                    ! $isExpense => __('Covered by the transfer'),
                                    $by['line']->is_credit => __('Deducted from the transfer'),
                                    default => __('Refunded by the transfer'),
                                } }}</span>
                            </td>
                            <td class="whitespace-nowrap px-3 py-2 text-end tabular-nums">{{ \App\Support\Money::format($by['amount'], app()->getLocale()) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @elseif (! $result->isReconciled())
        <p class="text-gray-600 dark:text-gray-300">{{ __('No bank statement line covers this yet. It will be reconciled when the amount reaches the business account and you process the statement.') }}</p>
    @endif

</div>
