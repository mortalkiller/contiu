{{--
    Resumo anual: the year's confirmed Social Security contributions, each with the quarterly
    declaration it comes from (App\Filament\Pages\AnnualFiscalReview).

    $rows:  list<array{month: string, amount: string, quarter: string, quarterLabel: string}>
    $total: string
--}}
@if ($rows === [])
    <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('No confirmed contributions') }}</p>
@else
    <div class="-mx-6 -my-2 overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50 text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-300">
                    <th scope="col" class="px-6 py-2.5 text-start font-medium">{{ __('Month') }}</th>
                    <th scope="col" class="px-3 py-2.5 text-end font-medium">{{ __('Confirmed') }}</th>
                    <th scope="col" class="px-3 py-2.5 pe-6 text-start font-medium">{{ __('Source breakdown') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($rows as $row)
                    <tr>
                        <td class="px-6 py-2.5">{{ $row['month'] }}</td>
                        <td class="px-3 py-2.5 text-end tabular-nums">{{ $row['amount'] }}</td>
                        <td class="px-3 py-2.5 pe-6">
                            <a class="text-primary-600 underline-offset-2 hover:underline dark:text-primary-400" href="{{ \App\Filament\Pages\SocialSecurity::getUrl(['quarter' => $row['quarter']]) }}">{{ $row['quarterLabel'] }}</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="border-t border-gray-200 bg-gray-50 font-semibold dark:border-white/10 dark:bg-white/5">
                    <td class="px-6 py-2.5">{{ __('Total') }}</td>
                    <td class="px-3 py-2.5 text-end tabular-nums">{{ $total }}</td>
                    <td class="pe-6"></td>
                </tr>
            </tfoot>
        </table>
    </div>
@endif
